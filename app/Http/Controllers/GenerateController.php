<?php

namespace App\Http\Controllers;

use App\Models\PromptProfile;
use App\Models\UsageLog;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use App\Services\SecretRedactor;
use App\Services\UsageTracker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class GenerateController extends Controller
{
    private const LANGUAGES = [
        'python', 'javascript', 'typescript', 'php', 'java', 'csharp', 'go', 'rust', 'sql', 'other',
    ];

    public function index(Request $request): View
    {
        $avgLatency = (int) round(
            UsageLog::query()
                ->where('user_id', $request->user()->id)
                ->where('success', true)
                ->whereNotNull('latency_ms')
                ->latest()
                ->limit(10)
                ->avg('latency_ms') ?? 0
        );

        return view('generate.index', [
            'languages' => self::LANGUAGES,
            'instruction' => old('instruction', ''),
            'context' => old('context', ''),
            'language' => old('language', 'php'),
            'profiles' => PromptProfile::query()->orderBy('name')->get(),
            'selectedProfile' => old('prompt_profile_id'),
            'health' => $this->fetchHealth(),
            'quota' => QuotaService::status($request->user()),
            'etaMs' => $avgLatency,
        ]);
    }

    public function stream(Request $request): StreamedResponse
    {
        // Generation + repair pass can take a long time on CPU-bound models.
        set_time_limit(1800);
        QuotaService::assertWithinQuota($request->user());

        $validated = $request->validate([
            'instruction' => ['required', 'string', 'min:3', 'max:20000'],
            'language' => ['required', 'string', 'in:'.implode(',', self::LANGUAGES)],
            'context' => ['nullable', 'string', 'max:100000'],
            'source_code' => ['nullable', 'string', 'max:200000'],
            'prompt_profile_id' => ['nullable', 'integer', 'exists:prompt_profiles,id'],
        ]);

        $instruction = trim($validated['instruction']);
        $language = $validated['language'];
        $context = trim((string) ($validated['context'] ?? ''));
        $sourceCode = trim((string) ($validated['source_code'] ?? ''));

        [$instruction, $secretsInstruction] = SecretRedactor::redact($instruction);
        [$context, $secretsContext] = $context !== ''
            ? SecretRedactor::redact($context)
            : [$context, 0];
        [$sourceCode, $secretsSource] = $sourceCode !== ''
            ? SecretRedactor::redact($sourceCode)
            : [$sourceCode, 0];
        $secretsRedacted = $secretsInstruction + $secretsContext + $secretsSource;

        $profile = null;
        if (! empty($validated['prompt_profile_id'])) {
            $profile = PromptProfile::query()->find((int) $validated['prompt_profile_id']);
        }

        $fastapiUrl = rtrim((string) config('services.fastapi.url'), '/');
        $user = $request->user();

        $storedCode = "Instruction:\n{$instruction}";
        if ($sourceCode !== '') {
            $storedCode .= "\n\nSource code:\n{$sourceCode}";
        }
        if ($context !== '') {
            $storedCode .= "\n\nContext:\n{$context}";
        }

        $title = mb_strimwidth(preg_replace('/\s+/', ' ', $instruction) ?: 'Generate', 0, 72, '…');

        AuditLogger::log($user, 'generate.started', 'review_run', null, [
            'language' => $language,
            'secrets_redacted' => $secretsRedacted,
            'profile' => $profile?->slug,
            'has_context' => $context !== '',
        ], $request);

        return response()->stream(function () use (
            $fastapiUrl,
            $instruction,
            $context,
            $sourceCode,
            $language,
            $profile,
            $request,
            $user,
            $secretsRedacted,
            $storedCode,
            $title
        ) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            $buffer = '';
            $success = false;
            $latencyMs = null;
            $parsed = false;
            $model = null;
            $errorMessage = null;
            $summary = null;
            $generatedCode = null;

            $payload = [
                'instruction' => $instruction,
                'language' => $language,
                'context' => $context !== '' ? $context : null,
                'source_code' => $sourceCode !== '' ? $sourceCode : null,
                'profile_instructions' => $profile?->extra_instructions,
            ];

            try {
                $response = Http::timeout(1800)
                    ->withHeaders([
                        'Accept' => 'text/event-stream',
                        'Content-Type' => 'application/json',
                    ])
                    ->withOptions(['stream' => true])
                    ->post($fastapiUrl.'/api/v1/generate/stream', $payload);

                if ($response->failed()) {
                    $detail = $response->json('detail') ?: 'Generate stream failed with HTTP '.$response->status();
                    $errorMessage = is_string($detail) ? $detail : json_encode($detail);
                    echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                    flush();
                } else {
                    $body = $response->toPsrResponse()->getBody();
                    while (! $body->eof()) {
                        $chunk = $body->read(1024);
                        if ($chunk === '') {
                            continue;
                        }
                        echo $chunk;
                        flush();
                        $buffer .= $chunk;
                        if (strlen($buffer) > 400000) {
                            $buffer = substr($buffer, -200000);
                        }
                    }

                    if (preg_match('/event:\s*done\s*\ndata:\s*(\{.*\})/s', $buffer, $matches)) {
                        $done = json_decode($matches[1], true);
                        if (is_array($done)) {
                            $success = true;
                            $latencyMs = isset($done['latency_ms']) ? (int) $done['latency_ms'] : null;
                            $parsed = (bool) ($done['parsed'] ?? false);
                            $model = $done['model'] ?? null;
                            $summary = $done['notes'] ?? ($done['summary'] ?? null);
                            $generatedCode = $done['code'] ?? ($done['improved_code'] ?? null);
                        }
                    } elseif (preg_match('/event:\s*error\s*\ndata:\s*(\{.*\})/s', $buffer, $matches)) {
                        $err = json_decode($matches[1], true);
                        $errorMessage = is_array($err) ? (string) ($err['detail'] ?? 'Generate failed') : 'Generate failed';
                    } else {
                        $errorMessage = 'Stream ended without a done event.';
                    }
                }
            } catch (ConnectionException $e) {
                $errorMessage = 'Cannot reach FastAPI at '.$fastapiUrl;
                echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            } catch (RequestException $e) {
                $errorMessage = $e->getMessage();
                echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            } catch (ValidationException $e) {
                $errorMessage = collect($e->errors())->flatten()->first() ?: 'Validation failed';
                echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            }

            $run = UsageTracker::saveRun(
                user: $user,
                request: $request,
                files: [[
                    'path' => 'instruction.'.$language,
                    'language' => $language,
                    'code' => $storedCode,
                ]],
                mode: 'stream',
                sourceType: 'generate',
                success: $success,
                latencyMs: $latencyMs,
                findingsCount: 0,
                parsed: $parsed,
                model: $model,
                errorMessage: $errorMessage,
                summary: $summary,
                review: $summary,
                findings: [],
                improvedCode: $generatedCode,
                chunksCount: 1,
                promptProfileId: $profile?->id,
                fileResults: $generatedCode ? [[
                    'path' => 'instruction.'.$language,
                    'language' => $language,
                    'summary' => $summary,
                    'findings' => [],
                    'improved_code' => $generatedCode,
                    'chunks_count' => 1,
                ]] : null,
                title: $title,
            );

            AuditLogger::log($user, $success ? 'generate.completed' : 'generate.failed', 'review_run', $run->id, [
                'language' => $language,
                'secrets_redacted' => $secretsRedacted,
                'latency_ms' => $latencyMs,
            ], $request);

            echo "event: meta\ndata: ".json_encode([
                'review_run_id' => $run->id,
                'quota' => QuotaService::status($user),
                'secrets_redacted' => $secretsRedacted,
            ], JSON_UNESCAPED_UNICODE)."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    /**
     * @return array{status: string, llm: string, model: ?string, detail: ?string}
     */
    private function fetchHealth(): array
    {
        $fastapiUrl = rtrim((string) config('services.fastapi.url'), '/');

        try {
            $response = Http::timeout(5)->acceptJson()->get($fastapiUrl.'/health');
            if ($response->successful()) {
                return [
                    'status' => (string) ($response->json('status') ?? 'ok'),
                    'llm' => (string) ($response->json('llm') ?? 'down'),
                    'model' => $response->json('model'),
                    'detail' => $response->json('detail'),
                ];
            }
        } catch (\Throwable $e) {
            return [
                'status' => 'down',
                'llm' => 'down',
                'model' => null,
                'detail' => 'FastAPI unreachable',
            ];
        }

        return [
            'status' => 'down',
            'llm' => 'down',
            'model' => null,
            'detail' => 'FastAPI health check failed',
        ];
    }
}

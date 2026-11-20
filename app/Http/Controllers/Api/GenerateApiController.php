<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PromptProfile;
use App\Services\AuditLogger;
use App\Services\QuotaService;
use App\Services\SecretRedactor;
use App\Services\UsageTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GenerateApiController extends Controller
{
    private const LANGUAGES = [
        'python', 'javascript', 'typescript', 'php', 'java', 'csharp', 'go', 'rust', 'sql', 'other',
    ];

    public function store(Request $request): JsonResponse
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

        $storedCode = "Instruction:\n{$instruction}";
        if ($sourceCode !== '') {
            $storedCode .= "\n\nSource code:\n{$sourceCode}";
        }
        if ($context !== '') {
            $storedCode .= "\n\nContext:\n{$context}";
        }

        $title = mb_strimwidth(preg_replace('/\s+/', ' ', $instruction) ?: 'Generate', 0, 72, '…');

        $payload = [
            'instruction' => $instruction,
            'language' => $language,
            'context' => $context !== '' ? $context : null,
            'source_code' => $sourceCode !== '' ? $sourceCode : null,
            'profile_instructions' => $profile?->extra_instructions,
        ];

        $fastapiUrl = rtrim((string) config('services.fastapi.url'), '/');
        $user = $request->user();

        AuditLogger::log($user, 'generate.api_started', 'review_run', null, [
            'language' => $language,
            'secrets_redacted' => $secretsRedacted,
            'has_source' => $sourceCode !== '',
            'has_context' => $context !== '',
        ], $request);

        try {
            $response = Http::timeout(1800)
                ->acceptJson()
                ->post($fastapiUrl.'/api/v1/generate', $payload);
        } catch (\Throwable $e) {
            return response()->json([
                'detail' => 'Cannot reach FastAPI at '.$fastapiUrl,
            ], 503);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?: 'Generate failed with HTTP '.$response->status();

            return response()->json([
                'detail' => is_string($detail) ? $detail : json_encode($detail),
            ], $response->status() >= 400 ? $response->status() : 502);
        }

        $data = $response->json() ?: [];
        $generatedCode = $data['code'] ?? null;
        $notes = $data['notes'] ?? null;

        $run = UsageTracker::saveRun(
            user: $user,
            request: $request,
            files: [[
                'path' => 'instruction.'.$language,
                'language' => $language,
                'code' => $storedCode,
            ]],
            mode: 'api',
            sourceType: 'generate',
            success: true,
            latencyMs: isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            findingsCount: 0,
            parsed: (bool) ($data['parsed'] ?? false),
            model: $data['model'] ?? null,
            errorMessage: null,
            summary: $notes,
            review: $notes,
            findings: [],
            improvedCode: $generatedCode,
            chunksCount: 1,
            promptProfileId: $profile?->id,
            fileResults: $generatedCode ? [[
                'path' => 'instruction.'.$language,
                'language' => $language,
                'summary' => $notes,
                'findings' => [],
                'improved_code' => $generatedCode,
                'chunks_count' => 1,
            ]] : null,
            title: $title,
        );

        AuditLogger::log($user, 'generate.api_completed', 'review_run', $run->id, [
            'secrets_redacted' => $secretsRedacted,
            'latency_ms' => $data['latency_ms'] ?? null,
        ], $request);

        return response()->json([
            'review_run_id' => $run->id,
            'notes' => $notes,
            'code' => $generatedCode,
            'model' => $data['model'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null,
            'parsed' => (bool) ($data['parsed'] ?? false),
            'language' => $data['language'] ?? $language,
            'mode' => $sourceCode !== '' ? 'modify' : 'generate',
        ]);
    }
}

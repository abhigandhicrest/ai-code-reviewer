<?php

namespace App\Http\Controllers;

use App\Models\CodingStandard;
use App\Models\PromptProfile;
use App\Models\UsageLog;
use App\Services\AuditLogger;
use App\Services\CodeIngest;
use App\Services\FindingLocator;
use App\Services\GitDiffService;
use App\Services\LinterService;
use App\Services\QuotaService;
use App\Services\SuppressService;
use App\Services\UsageTracker;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReviewController extends Controller
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

        return view('review.index', [
            'languages' => self::LANGUAGES,
            'code' => old('code', ''),
            'language' => old('language', 'python'),
            'projectPath' => old('project_path', ''),
            'profiles' => PromptProfile::query()->orderBy('name')->get(),
            'selectedProfile' => old('prompt_profile_id'),
            'review' => null,
            'summary' => null,
            'findings' => [],
            'improvedCode' => null,
            'parsed' => false,
            'model' => null,
            'latencyMs' => null,
            'error' => null,
            'health' => $this->fetchHealth(),
            'quota' => QuotaService::status($request->user()),
            'etaMs' => $avgLatency,
            'allowedRoots' => CodeIngest::allowedRoots(),
            'maxFiles' => (int) config('services.review.max_files', 0),
        ]);
    }

    public function stream(Request $request): StreamedResponse|JsonResponse
    {
        // Multi-file reviews on CPU-bound local models can exceed 15 minutes.
        set_time_limit(1800);
        QuotaService::assertWithinQuota($request->user());

        $language = (string) $request->input('language', 'python');
        try {
            $ingest = CodeIngest::fromRequest($request, $language);
        } catch (ValidationException $e) {
            $detail = collect($e->errors())->flatten()->first() ?: 'Invalid review input.';

            return response()->json([
                'detail' => $detail,
                'errors' => $e->errors(),
            ], 422);
        }
        $files = $ingest['files'];
        $sourceType = $ingest['source_type'];
        $secretsRedacted = $ingest['secrets_redacted'];
        $truncated = (bool) ($ingest['truncated'] ?? false);
        $projectLabel = $ingest['project_label'] ?? null;
        $projectRoot = $ingest['project_root'] ?? null;
        $ingestNotes = $ingest['ingest_notes'] ?? [];
        $maxFiles = (int) config('services.review.max_files', 0);

        $profile = null;
        if ($request->filled('prompt_profile_id')) {
            $profile = PromptProfile::query()->find($request->integer('prompt_profile_id'));
        }

        $fastapiUrl = rtrim((string) config('services.fastapi.url'), '/');
        $user = $request->user();

        AuditLogger::log($user, 'review.started', 'review_run', null, [
            'files_count' => count($files),
            'source_type' => $sourceType,
            'secrets_redacted' => $secretsRedacted,
            'truncated' => $truncated,
            'project' => $projectLabel,
            'profile' => $profile?->slug,
            'selected_count' => $ingest['selected_count'] ?? null,
            'scanned_count' => $ingest['scanned_count'] ?? null,
            'review_mode' => $sourceType === 'diff' ? 'diff' : 'full',
        ], $request);

        return response()->stream(function () use ($fastapiUrl, $files, $sourceType, $profile, $request, $user, $secretsRedacted, $truncated, $maxFiles, $projectLabel, $projectRoot, $ingestNotes) {
            while (ob_get_level() > 0) {
                ob_end_flush();
            }

            foreach ($ingestNotes as $note) {
                echo "event: token\ndata: ".json_encode([
                    'content' => '[note] '.$note."\n",
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            }

            if ($truncated) {
                echo "event: token\ndata: ".json_encode([
                    'content' => "[note] Truncated to {$maxFiles} files".($projectLabel ? " under {$projectLabel}" : '').".\n",
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            }

            $buffer = '';
            $success = false;
            $latencyMs = null;
            $findingsCount = 0;
            $parsed = false;
            $model = null;
            $errorMessage = null;
            $summary = null;
            $review = null;
            $findings = [];
            $improvedCode = null;
            $chunksCount = 1;
            $fileResults = [];
            $done = null;

            $generateRaw = $request->input('generate_code', '1');
            if (is_array($generateRaw)) {
                $generateRaw = end($generateRaw);
            }
            $generateCode = filter_var($generateRaw, FILTER_VALIDATE_BOOLEAN);

            $standardsByLanguage = CodingStandard::enabledRulesByLanguage();
            $payload = [
                'files' => $files,
                'language' => $files[0]['language'] ?? 'other',
                'focus' => $profile?->focus_json ?: ['bugs', 'security', 'improve'],
                'profile' => $profile?->slug,
                'profile_instructions' => $profile?->extra_instructions,
                'generate_code' => $generateCode,
                'review_mode' => $sourceType === 'diff' ? 'diff' : 'full',
                'standards_by_language' => $standardsByLanguage ?: new \stdClass,
            ];

            $linterResult = ['findings' => [], 'notes' => []];
            try {
                $linterResult = LinterService::analyze($files);
            } catch (\Throwable $e) {
                $linterResult['notes'][] = 'Linters skipped (error).';
            }
            foreach ($linterResult['notes'] as $note) {
                echo "event: token\ndata: ".json_encode([
                    'content' => '[note] '.$note."\n",
                ], JSON_UNESCAPED_UNICODE)."\n\n";
                flush();
            }

            try {
                $response = Http::timeout(1800)
                    ->withHeaders([
                        'Accept' => 'text/event-stream',
                        'Content-Type' => 'application/json',
                    ])
                    ->withOptions(['stream' => true])
                    ->post($fastapiUrl.'/api/v1/review/stream', $payload);

                if ($response->failed()) {
                    $detail = $response->json('detail') ?: 'Review stream failed with HTTP '.$response->status();
                    $errorMessage = is_string($detail) ? $detail : json_encode($detail);
                    echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                    flush();
                } else {
                    $body = $response->toPsrResponse()->getBody();
                    $idleReads = 0;
                    while (! $body->eof()) {
                        $chunk = $body->read(8192);
                        if ($chunk === '') {
                            $idleReads++;
                            if ($idleReads > 300) {
                                $errorMessage = 'Review stream stalled (no data from API). Try fewer files or a smaller path.';
                                break;
                            }
                            usleep(100000);
                            continue;
                        }
                        $idleReads = 0;
                        echo $chunk;
                        flush();
                        $buffer .= $chunk;
                        if (strlen($buffer) > 800000) {
                            $buffer = substr($buffer, -400000);
                        }
                    }

                    if (preg_match('/event:\s*done\s*\ndata:\s*(\{.*\})/s', $buffer, $matches)) {
                        $done = json_decode($matches[1], true);
                        if (is_array($done)) {
                            $success = true;
                            $latencyMs = isset($done['latency_ms']) ? (int) $done['latency_ms'] : null;
                            $findings = is_array($done['findings'] ?? null) ? $done['findings'] : [];
                            $linterFindings = is_array($linterResult['findings'] ?? null) ? $linterResult['findings'] : [];
                            if ($linterFindings !== []) {
                                $findings = self::mergeFindings($findings, $linterFindings);
                                $done['findings'] = $findings;
                                $byTool = [];
                                foreach ($linterFindings as $lf) {
                                    $title = (string) ($lf['title'] ?? '');
                                    $tool = 'linter';
                                    if (str_contains($title, 'PHPCS') || str_contains($title, 'Generic.') || str_contains($title, 'PSR12')) {
                                        $tool = 'PHPCS';
                                    } elseif (preg_match('/\[standard\]\s*([A-Z]\d+)/', $title)) {
                                        $tool = 'Ruff/flake8';
                                    } elseif (str_contains($title, 'eslint') || str_contains(strtolower($title), 'eslint')) {
                                        $tool = 'ESLint';
                                    }
                                    $byTool[$tool] = ($byTool[$tool] ?? 0) + 1;
                                }
                                $parts = [];
                                foreach ($byTool as $tool => $n) {
                                    $parts[] = "{$n} {$tool}";
                                }
                                $mergeNote = '[note] Linter: '.implode(', ', $parts).' issue(s) merged'."\n";
                                echo "event: token\ndata: ".json_encode(['content' => $mergeNote], JSON_UNESCAPED_UNICODE)."\n\n";
                                echo "event: done\ndata: ".json_encode($done, JSON_UNESCAPED_UNICODE)."\n\n";
                                flush();
                            }
                            $findingsCount = count($findings);
                            $parsed = (bool) ($done['parsed'] ?? false);
                            $model = $done['model'] ?? null;
                            $summary = $done['summary'] ?? null;
                            $review = $done['review'] ?? null;
                            $improvedCode = $done['improved_code'] ?? null;
                            $chunksCount = (int) ($done['chunks_count'] ?? 1);
                            $fileResults = $done['file_results'] ?? [];
                        }
                    } elseif (preg_match('/event:\s*error\s*\ndata:\s*(\{.*\})/s', $buffer, $matches)) {
                        $err = json_decode($matches[1], true);
                        $errorMessage = is_array($err) ? (string) ($err['detail'] ?? 'Review failed') : 'Review failed';
                    } elseif ($errorMessage === null) {
                        $errorMessage = 'Stream ended without a done event. The model may still be loading, or the connection dropped during a long multi-file review.';
                    }

                    if (! $success && $errorMessage) {
                        echo "event: error\ndata: ".json_encode(['detail' => $errorMessage], JSON_UNESCAPED_UNICODE)."\n\n";
                        flush();
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
            }

            $run = UsageTracker::saveRun(
                user: $user,
                request: $request,
                files: $files,
                mode: 'stream',
                sourceType: $sourceType,
                success: $success,
                latencyMs: $latencyMs,
                findingsCount: $findingsCount,
                parsed: $parsed,
                model: $model,
                errorMessage: $errorMessage,
                summary: $summary,
                review: $review,
                findings: $findings,
                improvedCode: $improvedCode,
                chunksCount: $chunksCount,
                promptProfileId: $profile?->id,
                fileResults: $fileResults,
                projectRoot: $projectRoot,
            );

            AuditLogger::log($user, $success ? 'review.completed' : 'review.failed', 'review_run', $run->id, [
                'files_count' => count($files),
                'findings_count' => $findingsCount,
                'secrets_redacted' => $secretsRedacted,
                'latency_ms' => $latencyMs,
            ], $request);

            echo "event: meta\ndata: ".json_encode([
                'review_run_id' => $run->id,
                'quota' => QuotaService::status($user),
                'secrets_redacted' => $secretsRedacted,
                'error' => $success ? null : $errorMessage,
            ], JSON_UNESCAPED_UNICODE)."\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'Connection' => 'keep-alive',
        ]);
    }

    public function scan(Request $request): JsonResponse
    {
        // Directory walking can exceed PHP's default 60s limit on large trees.
        set_time_limit(300);

        $request->validate([
            'project_path' => ['required', 'string', 'max:1000'],
            'ignore_patterns' => ['nullable', 'string', 'max:20000'],
        ]);

        try {
            $result = CodeIngest::scanProjectPath(
                (string) $request->input('project_path'),
                $request->input('ignore_patterns')
            );
        } catch (ValidationException $e) {
            $detail = collect($e->errors())->flatten()->first() ?: 'Scan failed.';

            return response()->json([
                'detail' => $detail,
                'errors' => $e->errors(),
            ], 422);
        }

        AuditLogger::log($request->user(), 'review.scanned', 'project_path', null, [
            'files' => count($result['files']),
            'truncated' => $result['truncated'],
            'ignored_count' => $result['ignored_count'],
            'project' => $result['project_label'],
        ], $request);

        return response()->json($result);
    }

    public function gitDiff(Request $request): JsonResponse
    {
        // Up to four git subprocess calls can each take up to 15s.
        set_time_limit(120);

        $request->validate([
            'project_path' => ['required', 'string', 'max:1000'],
            'git_base' => ['nullable', 'string', 'max:200'],
            'git_head' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $result = GitDiffService::diff(
                (string) $request->input('project_path'),
                (string) $request->input('git_base', 'main'),
                (string) $request->input('git_head', 'HEAD')
            );
        } catch (ValidationException $e) {
            $detail = collect($e->errors())->flatten()->first() ?: 'Git diff failed.';

            return response()->json([
                'detail' => $detail,
                'errors' => $e->errors(),
            ], 422);
        }

        AuditLogger::log($request->user(), 'review.git_diff', 'project_path', null, [
            'project' => $result['project_label'],
            'base' => $result['base'],
            'head' => $result['head'],
            'bytes' => strlen($result['diff']),
        ], $request);

        return response()->json([
            'diff' => $result['diff'],
            'base' => $result['base'],
            'head' => $result['head'],
            'project_root' => $result['project_root'],
            'project_label' => $result['project_label'],
            'bytes' => strlen($result['diff']),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $ai
     * @param  array<int, array<string, mixed>>  $linter
     * @return array<int, array<string, mixed>>
     */
    private static function mergeFindings(array $ai, array $linter): array
    {
        $seen = [];
        $out = [];
        foreach (array_merge($ai, $linter) as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $key = strtolower(
                ($finding['file_path'] ?? '').'|'.trim((string) ($finding['title'] ?? ''))
            );
            if ($key !== '|' && isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $finding;
        }

        return $out;
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

<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CodingStandard;
use App\Models\PromptProfile;
use App\Services\AuditLogger;
use App\Services\DiffIngest;
use App\Services\QuotaService;
use App\Services\SecretRedactor;
use App\Services\UsageTracker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class ReviewApiController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        // CI reviews on CPU-bound local models can take a long time.
        set_time_limit(1800);
        QuotaService::assertWithinQuota($request->user());

        $validated = $request->validate([
            'diff_text' => ['nullable', 'string'],
            'files' => ['nullable', 'array', 'min:1'],
            'files.*.path' => ['required_with:files', 'string', 'max:500'],
            'files.*.language' => ['nullable', 'string', 'max:40'],
            'files.*.code' => ['required_with:files', 'string'],
            'language' => ['nullable', 'string', 'max:40'],
            'generate_code' => ['nullable', 'boolean'],
            'prompt_profile_id' => ['nullable', 'integer', 'exists:prompt_profiles,id'],
            'fail_on' => ['nullable', 'array'],
            'fail_on.*' => ['in:high,medium,low'],
        ]);

        if (empty($validated['diff_text']) && empty($validated['files'])) {
            throw ValidationException::withMessages([
                'diff_text' => 'Provide diff_text or files.',
            ]);
        }

        $sourceType = 'api';
        $reviewMode = 'full';
        $projectRoot = null;
        $files = [];

        if (! empty($validated['diff_text'])) {
            $parsed = DiffIngest::parse($validated['diff_text']);
            $files = $parsed['files'];
            $sourceType = 'diff';
            $reviewMode = 'diff';
        } else {
            foreach ($validated['files'] as $file) {
                [$code] = SecretRedactor::redact((string) $file['code']);
                $files[] = [
                    'path' => $file['path'],
                    'language' => $file['language'] ?? ($validated['language'] ?? 'other'),
                    'code' => $code,
                ];
            }
        }

        if ($files === []) {
            throw ValidationException::withMessages([
                'files' => 'No reviewable files found.',
            ]);
        }

        $profile = null;
        if (! empty($validated['prompt_profile_id'])) {
            $profile = PromptProfile::query()->find((int) $validated['prompt_profile_id']);
        }

        $payload = [
            'files' => $files,
            'language' => $files[0]['language'] ?? ($validated['language'] ?? 'other'),
            'focus' => $profile?->focus_json ?: ['bugs', 'security', 'improve'],
            'profile' => $profile?->slug,
            'profile_instructions' => $profile?->extra_instructions,
            'generate_code' => (bool) ($validated['generate_code'] ?? false),
            'review_mode' => $reviewMode,
            'standards_by_language' => CodingStandard::enabledRulesByLanguage() ?: new \stdClass,
        ];

        $fastapiUrl = rtrim((string) config('services.fastapi.url'), '/');
        $user = $request->user();

        AuditLogger::log($user, 'review.api_started', 'review_run', null, [
            'files_count' => count($files),
            'source_type' => $sourceType,
        ], $request);

        try {
            $response = Http::timeout(1800)
                ->acceptJson()
                ->post($fastapiUrl.'/api/v1/review', $payload);
        } catch (\Throwable $e) {
            return response()->json([
                'detail' => 'Cannot reach FastAPI at '.$fastapiUrl,
            ], 503);
        }

        if ($response->failed()) {
            $detail = $response->json('detail') ?: 'Review failed with HTTP '.$response->status();

            return response()->json([
                'detail' => is_string($detail) ? $detail : json_encode($detail),
            ], $response->status() >= 400 ? $response->status() : 502);
        }

        $data = $response->json() ?: [];
        $findings = is_array($data['findings'] ?? null) ? $data['findings'] : [];
        $failOn = array_map('strtolower', $validated['fail_on'] ?? ['high']);
        $failed = false;
        foreach ($findings as $finding) {
            $sev = strtolower((string) ($finding['severity'] ?? 'medium'));
            if (in_array($sev, $failOn, true)) {
                $failed = true;
                break;
            }
        }

        $run = UsageTracker::saveRun(
            user: $user,
            request: $request,
            files: $files,
            mode: 'api',
            sourceType: $sourceType,
            success: true,
            latencyMs: isset($data['latency_ms']) ? (int) $data['latency_ms'] : null,
            findingsCount: count($findings),
            parsed: (bool) ($data['parsed'] ?? false),
            model: $data['model'] ?? null,
            errorMessage: null,
            summary: $data['summary'] ?? null,
            review: $data['review'] ?? null,
            findings: $findings,
            improvedCode: $data['improved_code'] ?? null,
            chunksCount: (int) ($data['chunks_count'] ?? 1),
            promptProfileId: $profile?->id,
            fileResults: $data['file_results'] ?? null,
            title: 'CI review ('.count($files).' files)',
            projectRoot: $projectRoot,
        );

        AuditLogger::log($user, 'review.api_completed', 'review_run', $run->id, [
            'findings_count' => count($findings),
            'failed' => $failed,
        ], $request);

        return response()->json([
            'review_run_id' => $run->id,
            'summary' => $data['summary'] ?? null,
            'findings' => $findings,
            'findings_count' => count($findings),
            'failed' => $failed,
            'fail_on' => $failOn,
            'model' => $data['model'] ?? null,
            'latency_ms' => $data['latency_ms'] ?? null,
        ], $failed ? 422 : 200);
    }
}

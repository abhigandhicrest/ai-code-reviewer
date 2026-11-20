<?php

namespace App\Services;

use App\Models\PromptProfile;
use App\Models\ReviewRun;
use App\Models\ReviewRunFile;
use App\Models\UsageLog;
use App\Models\User;
use Illuminate\Http\Request;

class UsageTracker
{
    public static function estimateTokens(string $code): int
    {
        return max(1, (int) ceil(strlen($code) / 4));
    }

    /**
     * @param  array<int, array{path: string, language: string, code: string}>  $files
     * @param  array<int, array<string, mixed>>|null  $findings
     * @param  array<int, array<string, mixed>>|null  $fileResults
     */
    public static function saveRun(
        User $user,
        Request $request,
        array $files,
        string $mode,
        string $sourceType,
        bool $success,
        ?int $latencyMs = null,
        int $findingsCount = 0,
        bool $parsed = false,
        ?string $model = null,
        ?string $errorMessage = null,
        ?string $summary = null,
        ?string $review = null,
        ?array $findings = null,
        ?string $improvedCode = null,
        int $chunksCount = 1,
        ?int $promptProfileId = null,
        ?array $fileResults = null,
        ?string $title = null,
        ?string $projectRoot = null,
    ): ReviewRun {
        $combined = collect($files)->pluck('code')->implode("\n\n");
        $primaryLanguage = $files[0]['language'] ?? 'other';

        $run = ReviewRun::create([
            'user_id' => $user->id,
            'prompt_profile_id' => $promptProfileId,
            'language' => count($files) > 1 ? 'multi' : $primaryLanguage,
            'code' => $combined,
            'title' => $title ?: (count($files) > 1
                ? count($files).' files'
                : ($files[0]['path'] ?? null)),
            'summary' => $summary,
            'review' => $review,
            'findings' => $findings,
            'improved_code' => $improvedCode,
            'parsed' => $parsed,
            'success' => $success,
            'chunks_count' => max(1, $chunksCount),
            'files_count' => max(1, count($files)),
            'code_chars' => strlen($combined),
            'estimated_tokens' => self::estimateTokens($combined),
            'latency_ms' => $latencyMs,
            'findings_count' => $findingsCount,
            'mode' => $mode,
            'source_type' => $sourceType,
            'project_root' => $projectRoot,
            'model' => $model,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
        ]);

        $resultsByPath = [];
        foreach ($fileResults ?? [] as $result) {
            if (! empty($result['path'])) {
                $resultsByPath[$result['path']] = $result;
            }
        }

        foreach ($files as $file) {
            $result = $resultsByPath[$file['path']] ?? null;
            ReviewRunFile::create([
                'review_run_id' => $run->id,
                'path' => $file['path'],
                'language' => $file['language'],
                'code' => $file['code'],
                'summary' => $result['summary'] ?? null,
                'findings' => $result['findings'] ?? null,
                'improved_code' => $result['improved_code'] ?? null,
                'chunks_count' => (int) ($result['chunks_count'] ?? 1),
            ]);
        }

        UsageLog::create([
            'user_id' => $user->id,
            'review_run_id' => $run->id,
            'language' => $run->language,
            'code_chars' => $run->code_chars,
            'estimated_tokens' => $run->estimated_tokens,
            'latency_ms' => $latencyMs,
            'findings_count' => $findingsCount,
            'success' => $success,
            'parsed' => $parsed,
            'mode' => $mode,
            'model' => $model,
            'error_message' => $errorMessage ? mb_substr($errorMessage, 0, 2000) : null,
            'ip_address' => $request->ip(),
        ]);

        return $run;
    }
}

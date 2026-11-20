<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

class DiffIngest
{
    private const MAX_DIFF_BYTES = 1024 * 1024;

    private static function maxDiffFiles(): int
    {
        $configured = (int) config('services.review.max_files', 0);

        return $configured > 0 ? $configured : PHP_INT_MAX;
    }

    /**
     * @return array{files: array<int, array{path: string, language: string, code: string}>, truncated: bool}
     */
    public static function fromRequestText(?string $text, ?UploadedFile $file = null): array
    {
        $raw = '';
        if ($file instanceof UploadedFile) {
            if ($file->getSize() > self::MAX_DIFF_BYTES) {
                throw ValidationException::withMessages([
                    'diff_file' => 'Diff file exceeds 1 MB.',
                ]);
            }
            $raw = (string) @file_get_contents($file->getRealPath());
        }
        if (trim((string) $text) !== '') {
            $raw = (string) $text;
        }

        $raw = str_replace("\r\n", "\n", $raw);
        if (trim($raw) === '') {
            throw ValidationException::withMessages([
                'diff_text' => 'Paste a unified git diff or upload a .diff/.patch file.',
            ]);
        }
        if (strlen($raw) > self::MAX_DIFF_BYTES) {
            throw ValidationException::withMessages([
                'diff_text' => 'Diff exceeds 1 MB.',
            ]);
        }

        return self::parse($raw);
    }

    /**
     * @return array{files: array<int, array{path: string, language: string, code: string}>, truncated: bool}
     */
    public static function parse(string $raw): array
    {
        $lines = explode("\n", $raw);
        $files = [];
        $currentPath = null;
        $hunkLines = [];
        $inHunk = false;
        $skipFile = false;

        $flush = function () use (&$files, &$currentPath, &$hunkLines, &$skipFile) {
            if ($skipFile || $currentPath === null || $hunkLines === []) {
                $hunkLines = [];

                return;
            }
            $code = implode("\n", $hunkLines);
            if (trim($code) === '') {
                $hunkLines = [];

                return;
            }
            if (! isset($files[$currentPath])) {
                $files[$currentPath] = [
                    'path' => $currentPath,
                    'language' => self::languageFromPath($currentPath),
                    'code' => '',
                ];
            }
            if ($files[$currentPath]['code'] !== '') {
                $files[$currentPath]['code'] .= "\n\n";
            }
            $files[$currentPath]['code'] .= $code;
            $hunkLines = [];
        };

        foreach ($lines as $line) {
            if (str_starts_with($line, 'diff --git ')) {
                $flush();
                $skipFile = false;
                $currentPath = null;
                $inHunk = false;
                continue;
            }

            if (str_starts_with($line, 'Binary files ') || str_starts_with($line, 'GIT binary patch')) {
                $skipFile = true;
                $currentPath = null;
                $hunkLines = [];
                $inHunk = false;
                continue;
            }

            if (str_starts_with($line, '+++ ')) {
                $path = self::parseDiffPath(substr($line, 4));
                if ($path === null || $path === '/dev/null') {
                    $skipFile = true;
                    $currentPath = null;
                } else {
                    $skipFile = false;
                    $currentPath = $path;
                }
                $inHunk = false;
                continue;
            }

            if (str_starts_with($line, '--- ')) {
                continue;
            }

            if (str_starts_with($line, '@@')) {
                if ($skipFile || $currentPath === null) {
                    continue;
                }
                if ($hunkLines !== []) {
                    $hunkLines[] = '';
                }
                $hunkLines[] = $line;
                $inHunk = true;
                continue;
            }

            if (! $inHunk || $skipFile || $currentPath === null) {
                continue;
            }

            if ($line === '' || $line[0] === ' ' || $line[0] === '+' || $line[0] === '-' || $line[0] === '\\') {
                $hunkLines[] = $line;
            }
        }
        $flush();

        $list = array_values($files);
        if ($list === []) {
            throw ValidationException::withMessages([
                'diff_text' => 'No reviewable file hunks found in the diff.',
            ]);
        }

        usort($list, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $max = self::maxDiffFiles();
        $truncated = count($list) > $max;

        return [
            'files' => array_slice($list, 0, $max),
            'truncated' => $truncated,
        ];
    }

    private static function parseDiffPath(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '/dev/null') {
            return null;
        }
        // strip tab and timestamp: b/path/file.php\t...
        if (str_contains($raw, "\t")) {
            $raw = strstr($raw, "\t", true) ?: $raw;
        }
        if (str_starts_with($raw, 'a/') || str_starts_with($raw, 'b/')) {
            $raw = substr($raw, 2);
        }
        $raw = trim($raw, '"');

        return $raw !== '' ? ltrim(str_replace('\\', '/', $raw), '/') : null;
    }

    private static function languageFromPath(string $path): string
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.blade.php')) {
            return 'php';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $map = [
            'py' => 'python', 'js' => 'javascript', 'ts' => 'typescript', 'tsx' => 'typescript',
            'jsx' => 'javascript', 'php' => 'php', 'java' => 'java', 'cs' => 'csharp',
            'go' => 'go', 'rs' => 'rust', 'sql' => 'sql', 'vue' => 'javascript',
        ];

        return $map[$ext] ?? 'other';
    }
}

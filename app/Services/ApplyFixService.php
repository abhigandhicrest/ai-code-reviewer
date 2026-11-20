<?php

namespace App\Services;

use App\Models\ReviewRun;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class ApplyFixService
{
    public static function canApply(ReviewRun $run): bool
    {
        if ($run->isGenerate() || $run->source_type === 'diff') {
            return false;
        }

        return $run->files()->whereNotNull('improved_code')->where('improved_code', '!=', '')->exists();
    }

    /**
     * @return array{applied: int, skipped: int, errors: list<string>, paths: list<string>}
     */
    public static function apply(ReviewRun $run, User $user, ?string $projectRootOverride, ?Request $request = null): array
    {
        $rootInput = trim((string) ($projectRootOverride ?: $run->project_root));
        if ($rootInput === '') {
            throw ValidationException::withMessages([
                'project_root' => 'Project root is required to apply fixes (set on the run or enter a path under allowed roots).',
            ]);
        }

        $resolved = CodeIngest::resolveAllowedDirectory($rootInput);
        $root = $resolved['realpath'];

        $run->loadMissing('files');
        $applied = 0;
        $skipped = 0;
        $errors = [];
        $paths = [];

        foreach ($run->files as $file) {
            $improved = (string) ($file->improved_code ?? '');
            if (trim($improved) === '') {
                $skipped++;
                continue;
            }
            if (self::looksLikeDiffHunk($improved)) {
                $skipped++;
                $errors[] = $file->path.': looks like a diff hunk (not applied)';
                continue;
            }

            $rel = ltrim(str_replace('\\', '/', (string) $file->path), '/');
            if ($rel === '' || str_contains($rel, '..')) {
                $skipped++;
                $errors[] = $file->path.': unsafe path';
                continue;
            }

            $target = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
            $realParent = realpath(dirname($target));
            if ($realParent === false) {
                // allow creating parent if under root
                $parent = dirname($target);
                if (! self::pathIsUnderRoot($parent, $root) && ! self::pathIsUnderRoot(dirname($parent), $root)) {
                    $skipped++;
                    $errors[] = $file->path.': outside allowed root';
                    continue;
                }
                if (! is_dir($parent) && ! @mkdir($parent, 0775, true)) {
                    $skipped++;
                    $errors[] = $file->path.': could not create directory';
                    continue;
                }
                $realParent = realpath($parent) ?: $parent;
            }

            $finalPath = $realParent.DIRECTORY_SEPARATOR.basename($target);
            if (! self::pathIsUnderRoot($finalPath, $root)) {
                $skipped++;
                $errors[] = $file->path.': outside allowed root';
                continue;
            }

            $backupRel = 'acr-backups/'.$run->id.'/'.$rel;
            if (is_file($finalPath)) {
                Storage::disk('local')->put($backupRel, (string) file_get_contents($finalPath));
            } else {
                Storage::disk('local')->put($backupRel.'.missing', '');
            }

            if (@file_put_contents($finalPath, $improved) === false) {
                $skipped++;
                $errors[] = $file->path.': write failed';
                continue;
            }

            $applied++;
            $paths[] = $rel;
        }

        if ($applied === 0 && $errors !== []) {
            throw ValidationException::withMessages([
                'confirm' => 'No files applied. '.implode('; ', array_slice($errors, 0, 5)),
            ]);
        }

        AuditLogger::log($user, 'review.apply', 'review_run', $run->id, [
            'applied' => $applied,
            'skipped' => $skipped,
            'project_root' => $root,
            'paths' => $paths,
        ], $request);

        if ($run->project_root !== $root) {
            $run->project_root = $root;
            $run->save();
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'errors' => $errors,
            'paths' => $paths,
        ];
    }

    public static function looksLikeDiffHunk(string $code): bool
    {
        $trimmed = ltrim($code);
        if (str_starts_with($trimmed, 'diff --git ') || str_starts_with($trimmed, '@@ ')) {
            return true;
        }
        $lines = preg_split('/\R/', $code) ?: [];
        $sample = array_slice($lines, 0, 40);
        $hunkish = 0;
        foreach ($sample as $line) {
            if ($line === '') {
                continue;
            }
            $c = $line[0];
            if ($c === '+' || $c === '-' || $c === ' ' || str_starts_with($line, '@@')) {
                $hunkish++;
            }
        }

        return $hunkish >= 8 && $hunkish >= (int) (count(array_filter($sample, fn ($l) => $l !== '')) * 0.7);
    }

    private static function pathIsUnderRoot(string $path, string $root): bool
    {
        $pathNorm = strtolower(str_replace('\\', '/', $path));
        $rootNorm = rtrim(strtolower(str_replace('\\', '/', $root)), '/');

        return $pathNorm === $rootNorm || str_starts_with($pathNorm, $rootNorm.'/');
    }
}

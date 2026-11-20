<?php

namespace App\Services;

use Symfony\Component\Process\Process;
use Illuminate\Validation\ValidationException;

class GitDiffService
{
    /**
     * @return array{diff: string, base: string, head: string, project_root: string, project_label: string}
     */
    public static function diff(string $projectPath, string $base, string $head): array
    {
        $resolved = CodeIngest::resolveAllowedDirectory($projectPath);
        $root = $resolved['realpath'];
        $base = trim($base) !== '' ? trim($base) : 'main';
        $head = trim($head) !== '' ? trim($head) : 'HEAD';

        if (! self::isSafeRef($base) || ! self::isSafeRef($head)) {
            throw ValidationException::withMessages([
                'git_base' => 'Invalid git ref. Use branch/tag/commit names only (no spaces or shell metacharacters).',
            ]);
        }

        $gitDir = self::runGit($root, ['rev-parse', '--is-inside-work-tree']);
        if (strtolower(trim($gitDir['stdout'])) !== 'true') {
            throw ValidationException::withMessages([
                'project_path' => 'Path is not inside a git working tree.',
            ]);
        }

        foreach (['base' => $base, 'head' => $head] as $label => $ref) {
            $check = self::runGit($root, ['rev-parse', '--verify', $ref]);
            if (! $check['ok']) {
                throw ValidationException::withMessages([
                    $label === 'base' ? 'git_base' : 'git_head' => "Git ref not found: {$ref}",
                ]);
            }
        }

        $range = $base.'...'.$head;
        $diff = self::runGit($root, ['diff', '--unified=3', $range], 30);
        if (! $diff['ok'] && trim($diff['stdout']) === '') {
            throw ValidationException::withMessages([
                'git_base' => 'git diff failed: '.mb_substr(trim($diff['stderr']) ?: 'unknown error', 0, 300),
            ]);
        }

        $text = $diff['stdout'];
        if (trim($text) === '') {
            throw ValidationException::withMessages([
                'git_base' => "No changes between {$base} and {$head}.",
            ]);
        }

        return [
            'diff' => $text,
            'base' => $base,
            'head' => $head,
            'project_root' => $root,
            'project_label' => basename($root),
        ];
    }

    private static function isSafeRef(string $ref): bool
    {
        // Allow common git refs: branches, tags, remotes, short/long SHAs, HEAD~
        return (bool) preg_match('/^[A-Za-z0-9._\\/@~^+{}=-]+$/', $ref)
            && ! str_contains($ref, '..')
            && strlen($ref) <= 200;
    }

    /**
     * @param  list<string>  $args
     * @return array{ok: bool, stdout: string, stderr: string}
     */
    private static function runGit(string $cwd, array $args, int $timeout = 15): array
    {
        $process = new Process(array_merge(['git', '-C', $cwd], $args));
        $process->setTimeout($timeout);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
        ];
    }
}

<?php

namespace App\Services;

use App\Models\CodingStandard;
use Symfony\Component\Process\Process;

class LinterService
{
    /**
     * @param  array<int, array{path: string, language: string, code: string}>  $files
     * @return array{findings: array<int, array<string, mixed>>, notes: list<string>}
     */
    public static function analyze(array $files): array
    {
        $findings = [];
        $notes = [];
        $timeout = max(5, (int) config('services.review.linters.timeout_seconds', 15));

        foreach ($files as $file) {
            $standard = CodingStandard::forLanguage($file['language'] ?? 'general');
            if (! $standard || $standard->linter === 'none' || ! $standard->enabled) {
                continue;
            }

            $result = match ($standard->linter) {
                'phpcs' => self::runPhpcs($file, $standard->linter_args, $timeout, $notes),
                'ruff' => self::runRuffOrFlake8($file, $standard->linter_args, $timeout, $notes),
                'eslint' => self::runEslint($file, $standard->linter_args, $timeout, $notes),
                default => [],
            };

            foreach ($result as $finding) {
                $findings[] = $finding;
            }
        }

        return [
            'findings' => $findings,
            'notes' => array_values(array_unique($notes)),
        ];
    }

    /**
     * @param  array{path: string, language: string, code: string}  $file
     * @param  list<string>  $notes
     * @return array<int, array<string, mixed>>
     */
    private static function runPhpcs(array $file, ?string $args, int $timeout, array &$notes): array
    {
        $bin = (string) config('services.review.linters.phpcs', 'phpcs');
        if (! self::commandExists($bin)) {
            $notes[] = 'PHPCS not installed — skipped.';

            return [];
        }

        $tmp = self::writeTempFile($file['code'], self::extensionFor($file));
        try {
            $cmd = array_merge(
                [$bin, '--report=json', '-q'],
                self::splitArgs($args ?: '--standard=PSR12'),
                [$tmp]
            );
            $process = new Process($cmd);
            $process->setTimeout($timeout);
            $process->run();
            $json = json_decode($process->getOutput() ?: $process->getErrorOutput(), true);
            if (! is_array($json)) {
                return [];
            }

            $out = [];
            foreach ($json['files'] ?? [] as $path => $info) {
                foreach ($info['messages'] ?? [] as $msg) {
                    $line = isset($msg['line']) ? (int) $msg['line'] : null;
                    $out[] = self::finding(
                        $file['path'],
                        '[standard] '.($msg['source'] ?? 'PHPCS').': '.($msg['message'] ?? 'Issue'),
                        (string) ($msg['message'] ?? 'Coding standard violation'),
                        $line ? 'line '.$line : null,
                        strtolower((string) ($msg['type'] ?? 'WARNING')) === 'error' ? 'medium' : 'low',
                        $line
                    );
                }
            }

            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @param  array{path: string, language: string, code: string}  $file
     * @param  list<string>  $notes
     * @return array<int, array<string, mixed>>
     */
    private static function runRuffOrFlake8(array $file, ?string $args, int $timeout, array &$notes): array
    {
        $ruff = (string) config('services.review.linters.ruff', 'ruff');
        $flake8 = (string) config('services.review.linters.flake8', 'flake8');

        if (self::commandExists($ruff)) {
            $tmp = self::writeTempFile($file['code'], '.py');
            try {
                $argParts = self::splitArgs($args ?: 'check');
                if (! in_array('check', $argParts, true)) {
                    array_unshift($argParts, 'check');
                }
                if (! in_array('--output-format=json', $argParts, true) && ! in_array('--output-format', $argParts, true)) {
                    $argParts[] = '--output-format=json';
                }
                $cmd = array_merge([$ruff], $argParts, [$tmp]);
                $process = new Process($cmd);
                $process->setTimeout($timeout);
                $process->run();
                $data = json_decode($process->getOutput(), true);
                if (! is_array($data)) {
                    return [];
                }
                $out = [];
                foreach ($data as $item) {
                    if (! is_array($item)) {
                        continue;
                    }
                    $code = $item['code'] ?? 'RUFF';
                    $msg = $item['message'] ?? 'Issue';
                    $line = $item['location']['row'] ?? null;
                    $out[] = self::finding(
                        $file['path'],
                        '[standard] '.$code.': '.$msg,
                        (string) $msg,
                        $line ? 'line '.$line : null,
                        'low',
                        $line ? (int) $line : null
                    );
                }

                return $out;
            } finally {
                @unlink($tmp);
            }
        }

        if (self::commandExists($flake8)) {
            $tmp = self::writeTempFile($file['code'], '.py');
            try {
                $process = new Process([$flake8, '--format=%(row)d:%(col)d:%(code)s:%(text)s', $tmp]);
                $process->setTimeout($timeout);
                $process->run();
                $out = [];
                foreach (preg_split('/\R/', trim($process->getOutput())) ?: [] as $line) {
                    if ($line === '') {
                        continue;
                    }
                    if (preg_match('/^(\d+):\d+:([^:]+):(.*)$/', $line, $m)) {
                        $out[] = self::finding(
                            $file['path'],
                            '[standard] '.$m[2].': '.trim($m[3]),
                            trim($m[3]),
                            'line '.$m[1],
                            'low',
                            (int) $m[1]
                        );
                    }
                }

                return $out;
            } finally {
                @unlink($tmp);
            }
        }

        $notes[] = 'Ruff/flake8 not installed — skipped.';

        return [];
    }

    /**
     * @param  array{path: string, language: string, code: string}  $file
     * @param  list<string>  $notes
     * @return array<int, array<string, mixed>>
     */
    private static function runEslint(array $file, ?string $args, int $timeout, array &$notes): array
    {
        $bin = (string) config('services.review.linters.eslint', 'eslint');
        $useNpx = false;
        if (! self::commandExists($bin)) {
            if (self::commandExists('npx')) {
                $useNpx = true;
            } else {
                $notes[] = 'ESLint not installed — skipped.';

                return [];
            }
        }

        $ext = str_contains(strtolower($file['language'] ?? ''), 'typescript') ? '.ts' : '.js';
        $tmp = self::writeTempFile($file['code'], $ext);
        try {
            $cmd = $useNpx
                ? array_merge(['npx', '--yes', 'eslint'], self::splitArgs($args), ['-f', 'json', $tmp])
                : array_merge([$bin], self::splitArgs($args), ['-f', 'json', $tmp]);
            $process = new Process($cmd);
            $process->setTimeout($timeout);
            $process->run();
            $data = json_decode($process->getOutput(), true);
            if (! is_array($data)) {
                return [];
            }
            $out = [];
            foreach ($data as $report) {
                foreach ($report['messages'] ?? [] as $msg) {
                    $rule = $msg['ruleId'] ?? 'eslint';
                    $text = $msg['message'] ?? 'Issue';
                    $sev = ((int) ($msg['severity'] ?? 1) >= 2) ? 'medium' : 'low';
                    $line = isset($msg['line']) ? (int) $msg['line'] : null;
                    $end = isset($msg['endLine']) ? (int) $msg['endLine'] : $line;
                    $out[] = self::finding(
                        $file['path'],
                        '[standard] '.$rule.': '.$text,
                        (string) $text,
                        $line ? 'line '.$line : null,
                        $sev,
                        $line,
                        $end
                    );
                }
            }

            return $out;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private static function finding(
        string $filePath,
        string $title,
        string $explanation,
        ?string $lineHint,
        string $severity,
        ?int $startLine = null,
        ?int $endLine = null
    ): array {
        return [
            'severity' => $severity,
            'title' => mb_substr($title, 0, 200),
            'explanation' => $explanation,
            'line_hint' => $lineHint,
            'file_path' => $filePath,
            'start_line' => $startLine,
            'end_line' => $endLine ?: $startLine,
            'source' => 'linter',
        ];
    }

    /**
     * @return list<string>
     */
    private static function splitArgs(?string $args): array
    {
        if ($args === null || trim($args) === '') {
            return [];
        }
        // simple split; enough for --standard=PSR12 style flags
        return preg_split('/\s+/', trim($args)) ?: [];
    }

    private static function extensionFor(array $file): string
    {
        $lang = strtolower((string) ($file['language'] ?? ''));
        $path = (string) ($file['path'] ?? '');
        if (str_ends_with(strtolower($path), '.php') || $lang === 'php') {
            return '.php';
        }
        if ($lang === 'python' || str_ends_with(strtolower($path), '.py')) {
            return '.py';
        }
        if (str_contains($lang, 'typescript') || str_ends_with(strtolower($path), '.ts')) {
            return '.ts';
        }

        return '.js';
    }

    private static function writeTempFile(string $code, string $ext): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'acr_lint_');
        $path = $tmp.$ext;
        @rename($tmp, $path);
        file_put_contents($path, $code);

        return $path;
    }

    private static function commandExists(string $bin): bool
    {
        if ($bin === '' || str_contains($bin, "\0")) {
            return false;
        }
        if (is_file($bin)) {
            return true;
        }
        $isWin = PHP_OS_FAMILY === 'Windows';
        $process = new Process($isWin ? ['where', $bin] : ['which', $bin]);
        $process->setTimeout(5);
        $process->run();

        return $process->isSuccessful() && trim($process->getOutput()) !== '';
    }
}

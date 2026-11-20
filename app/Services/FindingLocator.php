<?php

namespace App\Services;

class FindingLocator
{
    /**
     * @param  array<int, array<string, mixed>>  $findings
     * @return array<int, array<string, mixed>>
     */
    public static function normalize(array $findings): array
    {
        $out = [];
        foreach ($findings as $finding) {
            if (! is_array($finding)) {
                continue;
            }
            $start = self::intOrNull($finding['start_line'] ?? null) ?? self::fromHint($finding['line_hint'] ?? null);
            $end = self::intOrNull($finding['end_line'] ?? null) ?? $start;
            if ($start && $end && $end < $start) {
                $end = $start;
            }
            if ($start && empty($finding['line_hint'])) {
                $finding['line_hint'] = $end && $end !== $start ? "lines {$start}-{$end}" : "line {$start}";
            }
            $finding['start_line'] = $start;
            $finding['end_line'] = $end;
            $out[] = $finding;
        }

        return $out;
    }

    public static function fingerprint(array $finding): string
    {
        $path = strtolower(str_replace('\\', '/', trim((string) ($finding['file_path'] ?? ''))));
        $title = strtolower(trim((string) ($finding['title'] ?? '')));
        $title = preg_replace('/^\[standard\]\s*/i', '', $title) ?: $title;

        return substr(hash('sha256', $path.'|'.$title), 0, 64);
    }

    private static function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $n = (int) $value;

            return $n > 0 ? $n : null;
        }

        return self::fromHint($value);
    }

    private static function fromHint(mixed $hint): ?int
    {
        if ($hint === null || $hint === '') {
            return null;
        }
        if (preg_match('/(?:lines?\s*)?(\d+)/i', (string) $hint, $m)) {
            $n = (int) $m[1];

            return $n > 0 ? $n : null;
        }

        return null;
    }
}

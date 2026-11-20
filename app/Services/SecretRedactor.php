<?php

namespace App\Services;

class SecretRedactor
{
    /**
     * @return array{0: string, 1: int} redacted text and count of replacements
     */
    public static function redact(string $code): array
    {
        $count = 0;
        $patterns = [
            '/-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]*PRIVATE KEY-----/i',
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/',
            '/(?i)\b(api[_-]?key|secret|token|password|passwd|pwd|access[_-]?key|private[_-]?key)\b(\s*[:=]\s*)([\'"]?)([^\s\'"]{6,})([\'"]?)/',
            '/(?i)(Authorization\s*:\s*Bearer\s+)([A-Za-z0-9\-._~+\/]+=*)/',
        ];

        $redacted = $code;
        foreach ($patterns as $pattern) {
            $redacted = preg_replace_callback($pattern, function ($matches) use (&$count) {
                $count++;
                if (isset($matches[5])) {
                    return $matches[1].$matches[2].$matches[3].'[REDACTED]'.$matches[5];
                }
                if (isset($matches[2]) && str_starts_with($matches[0], 'Authorization')) {
                    return $matches[1].'[REDACTED]';
                }

                return '[REDACTED]';
            }, $redacted) ?? $redacted;
        }

        return [$redacted, $count];
    }
}

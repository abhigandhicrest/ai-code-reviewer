<?php

namespace App\Services;

class ReviewIgnore
{
    /**
     * @param  list<string>  $patterns
     */
    public function __construct(private array $patterns = [])
    {
        $this->patterns = array_values(array_filter(array_map('trim', $this->patterns), fn ($p) => $p !== '' && ! str_starts_with($p, '#')));
    }

    public static function fromText(?string $text): self
    {
        if ($text === null || trim($text) === '') {
            return new self([]);
        }

        return new self(preg_split('/\R/', $text) ?: []);
    }

    public static function fromProjectRoot(string $rootRealPath, ?string $extraIgnoreText = null): self
    {
        $patterns = [];
        $file = rtrim($rootRealPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'.reviewignore';
        if (is_file($file)) {
            $contents = @file_get_contents($file);
            if (is_string($contents) && $contents !== '') {
                $patterns = array_merge($patterns, preg_split('/\R/', $contents) ?: []);
            }
        }

        $extra = self::fromText($extraIgnoreText);
        $patterns = array_merge($patterns, $extra->patterns);

        return new self($patterns);
    }

    /**
     * @return list<string>
     */
    public function patterns(): array
    {
        return $this->patterns;
    }

    public function matches(string $relativePath, bool $isDir = false): bool
    {
        $path = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($path === '') {
            return false;
        }

        foreach ($this->patterns as $pattern) {
            // v1: skip negation patterns
            if (str_starts_with($pattern, '!')) {
                continue;
            }
            if ($this->patternMatches($pattern, $path, $isDir)) {
                return true;
            }
        }

        return false;
    }

    private function patternMatches(string $pattern, string $path, bool $isDir): bool
    {
        $pattern = str_replace('\\', '/', trim($pattern));
        if ($pattern === '') {
            return false;
        }

        $dirOnly = str_ends_with($pattern, '/');
        if ($dirOnly) {
            $pattern = rtrim($pattern, '/');
            if (! $isDir && ! str_contains($path, '/')) {
                // file at root named like dir pattern — no match unless path has that segment
            }
        }

        // Directory-only: match if any path segment prefix matches
        if ($dirOnly) {
            if ($this->globMatch($pattern, $path) || $this->globMatch('**/'.$pattern, $path)) {
                return true;
            }
            $parts = explode('/', $path);
            $accum = '';
            foreach ($parts as $i => $part) {
                $accum = $accum === '' ? $part : $accum.'/'.$part;
                if ($this->globMatch($pattern, $accum) || $this->globMatch($pattern, $part)) {
                    return true;
                }
                // remaining is under this dir
                if ($i < count($parts) - 1 && ($this->globMatch($pattern, $accum) || basename($pattern) === $part && ! str_contains($pattern, '/'))) {
                    if ($this->globMatch($pattern, $part) || $this->globMatch($pattern, $accum)) {
                        return true;
                    }
                }
            }
        }

        if (! str_contains($pattern, '/')) {
            // Match basename or any path ending with /basename pattern
            if ($this->globMatch($pattern, basename($path))) {
                return true;
            }

            return $this->globMatch('**/'.$pattern, $path);
        }

        $pattern = ltrim($pattern, '/');

        return $this->globMatch($pattern, $path) || $this->globMatch('**/'.$pattern, $path);
    }

    private function globMatch(string $pattern, string $path): bool
    {
        $pattern = str_replace(['**/', '**'], ["\0DOUBLE\0", "\0DOUBLE\0"], $pattern);
        $regex = preg_quote($pattern, '#');
        $regex = str_replace('\*', '[^/]*', $regex);
        $regex = str_replace('\?', '[^/]', $regex);
        $regex = str_replace("\0DOUBLE\0", '.*', $regex);

        return (bool) preg_match('#^'.$regex.'$#i', $path);
    }
}

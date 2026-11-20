<?php

namespace App\Services;

use FilesystemIterator;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

class CodeIngest
{
    private const SKIP_DIR_PARTS = [
        'vendor', 'node_modules', '.git', '.svn', '__pycache__', 'dist', 'build', '.idea',
        'storage', 'coverage', '.cursor', 'cache',
    ];

    private const SKIP_PATH_FRAGMENTS = [
        'bootstrap/cache',
        'public/build',
    ];

    private const EXT_LANG = [
        'py' => 'python',
        'js' => 'javascript',
        'mjs' => 'javascript',
        'cjs' => 'javascript',
        'jsx' => 'javascript',
        'ts' => 'typescript',
        'tsx' => 'typescript',
        'php' => 'php',
        'blade.php' => 'php',
        'java' => 'java',
        'cs' => 'csharp',
        'go' => 'go',
        'rs' => 'rust',
        'sql' => 'sql',
        'txt' => 'other',
        'md' => 'other',
        'json' => 'other',
        'yml' => 'other',
        'yaml' => 'other',
        'html' => 'other',
        'css' => 'other',
        'vue' => 'javascript',
        'rb' => 'other',
        'kt' => 'other',
        'swift' => 'other',
    ];

    private const SCAN_LIST_MAX = 200;

    /**
     * @return array{
     *   files: array<int, array{path: string, language: string, code: string}>,
     *   source_type: string,
     *   secrets_redacted: int,
     *   truncated: bool,
     *   project_label: string|null,
     *   selected_count: int|null,
     *   scanned_count: int|null,
     *   project_root: string|null,
     *   ingest_notes: list<string>
     * }
     */
    public static function fromRequest(Request $request, string $fallbackLanguage): array
    {
        $files = [];
        $sourceType = 'paste';
        $secrets = 0;
        $truncated = false;
        $projectLabel = null;
        $projectRoot = null;
        $ingestNotes = [];
        $selectedCount = null;
        $scannedCount = null;
        $maxFiles = self::maxFiles();
        $extraIgnore = (string) $request->input('ignore_patterns', '');
        $selectedPaths = self::normalizeSelectedPaths($request->input('selected_paths', []));

        $projectPath = trim((string) $request->input('project_path', ''));
        $diffText = trim((string) $request->input('diff_text', ''));
        $diffFile = $request->file('diff_file');
        $gitBase = trim((string) $request->input('git_base', ''));
        $gitHead = trim((string) $request->input('git_head', ''));
        $useGitDiff = $projectPath !== '' && $request->boolean('use_git_diff') && $selectedPaths === [];

        if ($useGitDiff) {
            try {
                $git = GitDiffService::diff($projectPath, $gitBase !== '' ? $gitBase : 'main', $gitHead !== '' ? $gitHead : 'HEAD');
                $diff = DiffIngest::parse($git['diff']);
                $files = $diff['files'];
                $truncated = $diff['truncated'];
                $sourceType = 'diff';
                $projectLabel = $git['project_label'].' ('.$git['base'].'...'.$git['head'].')';
                $projectRoot = $git['project_root'];
                $ingestNotes[] = 'Reviewing git diff '.$git['base'].'...'.$git['head'].' (changed files only).';
            } catch (ValidationException $e) {
                $ingestNotes[] = collect($e->errors())->flatten()->first() ?: 'Git diff unavailable.';
                $ingestNotes[] = 'Falling back to full project scan.';
                $useGitDiff = false;
            }
        }

        if (! $useGitDiff && $projectPath !== '' && $files === []) {
            $resolved = self::resolveAllowedDirectory($projectPath);
            $ignore = ReviewIgnore::fromProjectRoot($resolved['realpath'], $extraIgnore);
            $projectLabel = basename($resolved['realpath']);
            $projectRoot = $resolved['realpath'];
            $sourceType = 'path';
            if ($selectedPaths !== []) {
                $files = self::loadSelectedFromProject($resolved['realpath'], $selectedPaths, $ignore);
                $selectedCount = count($files);
                $scannedCount = count($selectedPaths);
                if ($files === []) {
                    throw ValidationException::withMessages([
                        'selected_paths' => 'No selected files matched the project path. Scan again and pick files.',
                    ]);
                }
            } else {
                $walked = self::fromProjectPath($projectPath, $ignore, loadBodies: true);
                $files = $walked['files'];
                $truncated = $walked['truncated'];
                $projectLabel = $walked['project_label'];
                $scannedCount = $walked['scanned_count'] ?? count($files);
            }
        } elseif ($diffText !== '' || $diffFile instanceof UploadedFile) {
            $diff = DiffIngest::fromRequestText($diffText !== '' ? $diffText : null, $diffFile instanceof UploadedFile ? $diffFile : null);
            $files = $diff['files'];
            $truncated = $diff['truncated'];
            $sourceType = 'diff';
        } else {
            $dirUploads = $request->file('code_dir');
            if ($dirUploads) {
                if (! is_array($dirUploads)) {
                    $dirUploads = [$dirUploads];
                }
                $ignore = ReviewIgnore::fromText($extraIgnore);
                $dirResult = self::fromDirectoryUpload($dirUploads, self::rawFullPaths('code_dir'), $ignore);
                $files = $dirResult['files'];
                $truncated = $dirResult['truncated'];
                $sourceType = 'directory';
                $scannedCount = $dirResult['scanned_count'] ?? count($files);
                if ($selectedPaths !== []) {
                    $files = self::filterBySelectedPaths($files, $selectedPaths);
                    $selectedCount = count($files);
                    if ($files === []) {
                        throw ValidationException::withMessages([
                            'selected_paths' => 'No selected folder files to review.',
                        ]);
                    }
                }
            }

            if (empty($files)) {
                $zip = $request->file('code_zip');
                if ($zip instanceof UploadedFile) {
                    $zipResult = self::fromZip($zip);
                    $files = $zipResult['files'];
                    $truncated = $zipResult['truncated'];
                    $sourceType = 'zip';
                }

                $uploads = $request->file('code_files');
                if ($uploads) {
                    if (! is_array($uploads)) {
                        $uploads = [$uploads];
                    }
                    $sourceType = count($uploads) > 1 || $sourceType === 'zip' ? 'multi' : 'upload';
                    foreach ($uploads as $upload) {
                        if (! $upload instanceof UploadedFile) {
                            continue;
                        }
                        $files[] = self::fromUploadedFile($upload);
                    }
                }

                $single = $request->file('code_file');
                if ($single instanceof UploadedFile) {
                    $sourceType = $sourceType === 'paste' ? 'upload' : $sourceType;
                    $files[] = self::fromUploadedFile($single);
                }

                $paste = trim((string) $request->input('code', ''));
                if ($paste !== '' && empty($files)) {
                    $sourceType = 'paste';
                    $files[] = [
                        'path' => 'snippet.'.$fallbackLanguage,
                        'language' => $fallbackLanguage,
                        'code' => $paste,
                    ];
                }
            }
        }

        if (empty($files)) {
            throw ValidationException::withMessages([
                'code' => 'Paste code, upload files/folder/zip/diff, or enter a local project path.',
            ]);
        }

        if (count($files) > $maxFiles) {
            usort($files, fn ($a, $b) => strcmp($a['path'], $b['path']));
            $files = array_slice($files, 0, $maxFiles);
            $truncated = true;
        }

        $normalized = [];
        foreach ($files as $file) {
            [$code, $count] = SecretRedactor::redact($file['code']);
            $secrets += $count;
            $normalized[] = [
                'path' => $file['path'],
                'language' => $file['language'],
                'code' => $code,
            ];
        }

        return [
            'files' => $normalized,
            'source_type' => $sourceType,
            'secrets_redacted' => $secrets,
            'truncated' => $truncated,
            'project_label' => $projectLabel,
            'selected_count' => $selectedCount,
            'scanned_count' => $scannedCount,
            'project_root' => $projectRoot,
            'ingest_notes' => $ingestNotes,
        ];
    }

    /**
     * @return array{
     *   files: array<int, array{path: string, language: string, bytes: int}>,
     *   truncated: bool,
     *   ignored_count: int,
     *   project_label: string,
     *   roots: list<string>
     * }
     */
    public static function scanProjectPath(string $path, ?string $extraIgnore = null): array
    {
        $resolved = self::resolveAllowedDirectory($path);
        $root = $resolved['realpath'];
        $ignore = ReviewIgnore::fromProjectRoot($root, $extraIgnore);
        $meta = self::walkProject($root, $ignore, loadBodies: false, listMax: self::SCAN_LIST_MAX);

        if ($meta['files'] === []) {
            throw ValidationException::withMessages([
                'project_path' => 'No reviewable source files found under that path.',
            ]);
        }

        return [
            'files' => $meta['files'],
            'truncated' => $meta['truncated'],
            'ignored_count' => $meta['ignored_count'],
            'project_label' => basename($root),
            'roots' => self::allowedRoots(),
        ];
    }

    /**
     * @param  array<int, array{path: string, language: string, code?: string, bytes?: int}>  $files
     * @param  list<string>  $selected
     * @return array<int, array{path: string, language: string, code: string}>
     */
    private static function filterBySelectedPaths(array $files, array $selected): array
    {
        $set = [];
        foreach ($selected as $path) {
            $set[ltrim(str_replace('\\', '/', $path), '/')] = true;
        }
        $out = [];
        foreach ($files as $file) {
            $p = ltrim(str_replace('\\', '/', $file['path']), '/');
            if (isset($set[$p]) && isset($file['code'])) {
                $out[] = [
                    'path' => $file['path'],
                    'language' => $file['language'],
                    'code' => $file['code'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $selected
     * @return array<int, array{path: string, language: string, code: string}>
     */
    private static function loadSelectedFromProject(string $root, array $selected, ReviewIgnore $ignore): array
    {
        $maxBytes = self::maxFileBytes();
        $maxFiles = self::maxFiles();
        $rootNorm = self::normalizePathForCompare($root);
        $out = [];

        foreach ($selected as $relative) {
            if (count($out) >= $maxFiles) {
                break;
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            if ($relative === '' || str_contains($relative, '..') || self::shouldSkipPath($relative) || $ignore->matches($relative) || ! self::isAllowedExtension($relative)) {
                continue;
            }
            $full = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($full);
            if ($real === false || ! is_file($real)) {
                continue;
            }
            $realNorm = self::normalizePathForCompare($real);
            if ($realNorm !== $rootNorm && ! str_starts_with($realNorm, $rootNorm.DIRECTORY_SEPARATOR)) {
                continue;
            }
            $size = filesize($real);
            if ($size === false || $size <= 0 || $size > $maxBytes) {
                continue;
            }
            $contents = @file_get_contents($real);
            if ($contents === false || ! self::looksText($contents)) {
                continue;
            }
            $out[] = [
                'path' => $relative,
                'language' => self::languageFromPath($relative),
                'code' => $contents,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function normalizeSelectedPaths(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (! is_array($raw)) {
            $raw = [$raw];
        }
        $paths = [];
        foreach ($raw as $item) {
            if (! is_string($item)) {
                continue;
            }
            $p = ltrim(str_replace('\\', '/', trim($item)), '/');
            if ($p !== '') {
                $paths[] = $p;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  array<int, UploadedFile>  $uploads
     * @param  array<int, string>  $fullPaths
     * @return array{files: array<int, array{path: string, language: string, code: string}>, truncated: bool, scanned_count: int}
     */
    private static function fromDirectoryUpload(array $uploads, array $fullPaths = [], ?ReviewIgnore $ignore = null): array
    {
        $ignore ??= new ReviewIgnore([]);
        $candidates = [];
        $maxBytes = self::maxFileBytes();
        $scanned = 0;

        foreach (array_values($uploads) as $index => $upload) {
            if (! $upload instanceof UploadedFile) {
                continue;
            }

            $relative = isset($fullPaths[$index]) ? (string) $fullPaths[$index] : '';
            if ($relative === '') {
                $relative = self::clientRelativePath($upload);
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            if ($relative === '' || self::shouldSkipPath($relative) || $ignore->matches($relative) || ! self::isAllowedExtension($relative)) {
                continue;
            }
            if ($upload->getSize() > $maxBytes) {
                continue;
            }

            $contents = @file_get_contents($upload->getRealPath());
            if ($contents === false || ! self::looksText($contents)) {
                continue;
            }

            $scanned++;
            $candidates[] = [
                'path' => $relative,
                'language' => self::languageFromPath($relative),
                'code' => $contents,
            ];
        }

        if (empty($candidates)) {
            throw ValidationException::withMessages([
                'code_dir' => 'No reviewable source files found in the selected folder.',
            ]);
        }

        usort($candidates, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $maxFiles = self::maxFiles();

        return [
            'files' => array_slice($candidates, 0, $maxFiles),
            'truncated' => count($candidates) > $maxFiles,
            'scanned_count' => $scanned,
        ];
    }

    /**
     * @return array{
     *   files: array<int, array{path: string, language: string, code: string}>,
     *   truncated: bool,
     *   project_label: string,
     *   scanned_count: int
     * }
     */
    private static function fromProjectPath(string $path, ?ReviewIgnore $ignore = null, bool $loadBodies = true): array
    {
        $resolved = self::resolveAllowedDirectory($path);
        $root = $resolved['realpath'];
        $ignore ??= ReviewIgnore::fromProjectRoot($root, null);
        $walked = self::walkProject($root, $ignore, loadBodies: $loadBodies, listMax: self::maxFiles());

        if ($walked['files'] === []) {
            throw ValidationException::withMessages([
                'project_path' => 'No reviewable source files found under that path.',
            ]);
        }

        $files = [];
        foreach ($walked['files'] as $file) {
            if (! isset($file['code'])) {
                continue;
            }
            $files[] = [
                'path' => $file['path'],
                'language' => $file['language'],
                'code' => $file['code'],
            ];
        }

        return [
            'files' => $files,
            'truncated' => $walked['truncated'],
            'project_label' => basename($root),
            'scanned_count' => $walked['total_matched'],
        ];
    }

    /**
     * @return array{
     *   files: array<int, array{path: string, language: string, bytes: int, code?: string}>,
     *   truncated: bool,
     *   ignored_count: int,
     *   total_matched: int
     * }
     */
    private static function walkProject(string $root, ReviewIgnore $ignore, bool $loadBodies, int $listMax): array
    {
        $maxBytes = self::maxFileBytes();
        $maxDepth = self::maxDepth();
        $candidates = [];
        $ignored = 0;

        $dirIterator = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
        $filter = new RecursiveCallbackFilterIterator($dirIterator, function ($current) use ($root, $ignore, &$ignored) {
            $name = $current->getFilename();
            if ($name === '.' || $name === '..') {
                return false;
            }
            // Never follow symlinks/junctions: a link loop would recurse forever.
            if ($current->isLink()) {
                return false;
            }
            $full = $current->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
            if ($current->isDir()) {
                if ($relative !== '' && (self::shouldSkipPath($relative) || $ignore->matches($relative, true))) {
                    if ($ignore->matches($relative, true)) {
                        $ignored++;
                    }

                    return false;
                }

                return true;
            }

            return true;
        });
        $iterator = new RecursiveIteratorIterator($filter, RecursiveIteratorIterator::LEAVES_ONLY);
        $iterator->setMaxDepth($maxDepth);

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if (! $fileInfo->isFile()) {
                continue;
            }

            $full = $fileInfo->getPathname();
            $relative = ltrim(str_replace('\\', '/', substr($full, strlen($root))), '/');
            if ($relative === '' || self::shouldSkipPath($relative) || ! self::isAllowedExtension($relative)) {
                continue;
            }
            if ($ignore->matches($relative)) {
                $ignored++;
                continue;
            }

            $size = (int) $fileInfo->getSize();
            if ($size <= 0 || $size > $maxBytes) {
                continue;
            }

            $entry = [
                'path' => $relative,
                'language' => self::languageFromPath($relative),
                'bytes' => $size,
            ];

            if ($loadBodies) {
                $contents = @file_get_contents($full);
                if ($contents === false || ! self::looksText($contents)) {
                    continue;
                }
                $entry['code'] = $contents;
            }

            $candidates[] = $entry;
        }

        usort($candidates, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $total = count($candidates);

        return [
            'files' => array_slice($candidates, 0, $listMax),
            'truncated' => $total > $listMax,
            'ignored_count' => $ignored,
            'total_matched' => $total,
        ];
    }

    /**
     * @return array{realpath: string}
     */
    public static function resolveAllowedDirectory(string $path): array
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, trim($path));
        if ($normalized === '' || str_contains($normalized, "\0")) {
            throw ValidationException::withMessages([
                'project_path' => 'Invalid project path.',
            ]);
        }

        $realpath = realpath($normalized);
        if ($realpath === false || ! is_dir($realpath)) {
            throw ValidationException::withMessages([
                'project_path' => 'Path does not exist or is not a directory.',
            ]);
        }

        $realpathNorm = self::normalizePathForCompare($realpath);
        $allowed = false;
        foreach (self::allowedRoots() as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) {
                continue;
            }
            $rootNorm = self::normalizePathForCompare($rootReal);
            if ($realpathNorm === $rootNorm || str_starts_with($realpathNorm, $rootNorm.DIRECTORY_SEPARATOR)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            throw ValidationException::withMessages([
                'project_path' => 'Path is outside allowed roots: '.implode(', ', self::allowedRoots()),
            ]);
        }

        return ['realpath' => $realpath];
    }

    /**
     * @return array{path: string, language: string, code: string}
     */
    private static function fromUploadedFile(UploadedFile $file): array
    {
        if ($file->getSize() > self::maxFileBytes()) {
            throw ValidationException::withMessages([
                'code_files' => $file->getClientOriginalName().' exceeds 1 MB.',
            ]);
        }

        $name = $file->getClientOriginalName() ?: 'upload.txt';
        if (self::shouldSkipPath($name)) {
            throw ValidationException::withMessages([
                'code_files' => 'Skipped path not allowed: '.$name,
            ]);
        }

        $contents = @file_get_contents($file->getRealPath());
        if ($contents === false) {
            throw ValidationException::withMessages([
                'code_files' => 'Could not read '.$name,
            ]);
        }

        return [
            'path' => $name,
            'language' => self::languageFromPath($name),
            'code' => $contents,
        ];
    }

    /**
     * @return array{files: array<int, array{path: string, language: string, code: string}>, truncated: bool}
     */
    private static function fromZip(UploadedFile $zipFile): array
    {
        if ($zipFile->getSize() > 10 * 1024 * 1024) {
            throw ValidationException::withMessages([
                'code_zip' => 'Zip exceeds 10 MB.',
            ]);
        }

        $zip = new ZipArchive();
        $opened = $zip->open($zipFile->getRealPath());
        if ($opened !== true) {
            throw ValidationException::withMessages([
                'code_zip' => 'Could not open zip archive.',
            ]);
        }

        $candidates = [];
        $maxBytes = self::maxFileBytes();
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $name = str_replace('\\', '/', (string) ($stat['name'] ?? ''));
            if ($name === '' || str_ends_with($name, '/')) {
                continue;
            }
            if (self::shouldSkipPath($name) || ! self::isAllowedExtension($name)) {
                continue;
            }
            $size = (int) ($stat['size'] ?? 0);
            if ($size <= 0 || $size > $maxBytes) {
                continue;
            }

            $contents = $zip->getFromIndex($i);
            if ($contents === false || ! self::looksText($contents)) {
                continue;
            }

            $candidates[] = [
                'path' => ltrim($name, '/'),
                'language' => self::languageFromPath($name),
                'code' => $contents,
            ];
        }
        $zip->close();

        if (empty($candidates)) {
            throw ValidationException::withMessages([
                'code_zip' => 'No reviewable text/source files found in zip.',
            ]);
        }

        usort($candidates, fn ($a, $b) => strcmp($a['path'], $b['path']));
        $maxFiles = self::maxFiles();

        return [
            'files' => array_slice($candidates, 0, $maxFiles),
            'truncated' => count($candidates) > $maxFiles,
        ];
    }

    private static function clientRelativePath(UploadedFile $file): string
    {
        if (method_exists($file, 'getClientOriginalPath')) {
            $path = (string) $file->getClientOriginalPath();
            if ($path !== '') {
                return str_replace('\\', '/', $path);
            }
        }

        return str_replace('\\', '/', $file->getClientOriginalName() ?: '');
    }

    /**
     * @return array<int, string>
     */
    private static function rawFullPaths(string $field): array
    {
        $entry = $_FILES[$field]['full_path'] ?? null;
        if ($entry === null) {
            return [];
        }
        if (is_string($entry)) {
            return [$entry];
        }
        if (! is_array($entry)) {
            return [];
        }

        $paths = [];
        foreach ($entry as $value) {
            if (is_string($value) && $value !== '') {
                $paths[] = $value;
            }
        }

        return $paths;
    }

    private static function shouldSkipPath(string $path): bool
    {
        $normalized = str_replace('\\', '/', strtolower($path));
        foreach (self::SKIP_PATH_FRAGMENTS as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        $parts = explode('/', $normalized);
        foreach ($parts as $part) {
            if (in_array($part, self::SKIP_DIR_PARTS, true)) {
                return true;
            }
        }

        return false;
    }

    private static function isAllowedExtension(string $path): bool
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.blade.php')) {
            return true;
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return array_key_exists($ext, self::EXT_LANG);
    }

    private static function languageFromPath(string $path): string
    {
        $lower = strtolower($path);
        if (str_ends_with($lower, '.blade.php')) {
            return 'php';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXT_LANG[$ext] ?? 'other';
    }

    private static function looksText(string $contents): bool
    {
        if (str_contains($contents, "\0")) {
            return false;
        }

        return mb_check_encoding($contents, 'UTF-8') || mb_check_encoding($contents, 'ISO-8859-1');
    }

    private static function maxFiles(): int
    {
        $configured = (int) config('services.review.max_files', 0);

        // 0 (or negative) = no file-count cap
        return $configured > 0 ? $configured : PHP_INT_MAX;
    }

    private static function maxFileBytes(): int
    {
        return max(1024, (int) config('services.review.max_file_bytes', 1024 * 1024));
    }

    private static function maxDepth(): int
    {
        return max(1, (int) config('services.review.max_depth', 8));
    }

    /**
     * @return list<string>
     */
    public static function allowedRoots(): array
    {
        $raw = config('services.review.allowed_roots', ['D:\\laragon\\www']);
        if (is_string($raw)) {
            $raw = preg_split('/\s*,\s*/', $raw) ?: [];
        }
        if (! is_array($raw)) {
            return ['D:\\laragon\\www'];
        }

        return array_values(array_filter(array_map('strval', $raw)));
    }

    private static function normalizePathForCompare(string $path): string
    {
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        if (PHP_OS_FAMILY === 'Windows') {
            return strtolower(rtrim($path, DIRECTORY_SEPARATOR));
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }
}

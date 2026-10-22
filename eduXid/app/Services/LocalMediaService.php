<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class LocalMediaService
{
    public const DISK = 'public';

    public function store(
        UploadedFile $file,
        string $directory,
        ?string $filename = null
    ): string {
        $directory = $this->normalizeDirectory($directory);

        $filename ??= $this->safeFilename(
            pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            strtolower((string) $file->getClientOriginalExtension())
        );

        $filename = $this->normalizeFilename($filename);

        $path = $file->storeAs($directory, $filename, self::DISK);

        if (!is_string($path) || trim($path) === '') {
            throw new RuntimeException('Gagal menyimpan media lokal.');
        }

        return $this->normalizeRelativePath($path);
    }

    public function replace(
        ?string $oldPath,
        UploadedFile $file,
        string $directory,
        ?string $filename = null
    ): string {
        $newPath = $this->store($file, $directory, $filename);

        if ($oldPath !== null && trim($oldPath) !== '') {
            $old = $this->normalizeLegacyPath($oldPath);

            if ($old !== null && $old !== $newPath) {
                $this->delete($old);
            }
        }

        return $newPath;
    }

    public function delete(?string $path): bool
    {
        $relative = $this->normalizeLegacyPath($path);

        if ($relative === null) {
            return false;
        }

        return Storage::disk(self::DISK)->delete($relative);
    }

    public function exists(?string $path): bool
    {
        $relative = $this->normalizeLegacyPath($path);

        return $relative !== null
            && Storage::disk(self::DISK)->exists($relative);
    }

    public function path(?string $path): ?string
    {
        $relative = $this->normalizeLegacyPath($path);

        if ($relative === null || !$this->exists($relative)) {
            return null;
        }

        $absolute = Storage::disk(self::DISK)->path($relative);
        $root = $this->storageRoot();

        $realRoot = realpath($root);
        $realFile = realpath($absolute);

        if ($realRoot === false || $realFile === false) {
            return null;
        }

        $rootComparable = $this->comparablePath($realRoot);
        $fileComparable = $this->comparablePath($realFile);

        if (
            $fileComparable !== $rootComparable
            && !str_starts_with($fileComparable, $rootComparable . DIRECTORY_SEPARATOR)
        ) {
            throw new RuntimeException('Media berada di luar storage lokal yang diizinkan.');
        }

        return $realFile;
    }

    public function url(?string $path): ?string
    {
        $relative = $this->normalizeLegacyPath($path);

        if ($relative === null) {
            return null;
        }

        return route('local-media.show', [
            'path' => str_replace('\\', '/', $relative),
        ]);
    }

    public function mimeType(?string $path): ?string
    {
        $absolute = $this->path($path);

        if ($absolute === null) {
            return null;
        }

        $mime = File::mimeType($absolute);

        return is_string($mime) && $mime !== ''
            ? $mime
            : 'application/octet-stream';
    }

    public function normalizeLegacyPath(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        if ($value === '') {
            return null;
        }

        $value = str_replace('\\', '/', $value);

        if (preg_match('#^https?://#i', $value) === 1) {
            $urlPath = parse_url($value, PHP_URL_PATH);

            if (!is_string($urlPath) || trim($urlPath) === '') {
                return null;
            }

            $value = $urlPath;
        }

        $decoded = rawurldecode($value);
        $decoded = str_replace('\\', '/', $decoded);

        $storageRoot = str_replace('\\', '/', $this->storageRoot());

        if (Str::startsWith(strtolower($decoded), strtolower($storageRoot . '/'))) {
            $decoded = substr($decoded, strlen($storageRoot) + 1);
        }

        $prefixes = [
            '/public/storage/',
            'public/storage/',
            '/storage/app/public/',
            'storage/app/public/',
            '/storage/',
            'storage/',
            '/media/',
            'media/',
        ];

        foreach ($prefixes as $prefix) {
            if (Str::startsWith(strtolower($decoded), strtolower($prefix))) {
                $decoded = substr($decoded, strlen($prefix));
                break;
            }
        }

        $decoded = ltrim($decoded, '/');

        try {
            return $this->normalizeRelativePath($decoded);
        } catch (RuntimeException) {
            return null;
        }
    }

    public function normalizeRelativePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', rawurldecode($path)));

        if ($path === '') {
            throw new RuntimeException('Path media kosong.');
        }

        if (
            str_contains($path, "\0")
            || preg_match('#^[A-Za-z]:/#', $path) === 1
            || str_starts_with($path, '/')
        ) {
            throw new RuntimeException('Path media absolut tidak diizinkan.');
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw new RuntimeException('Traversal path media tidak diizinkan.');
            }

            $segments[] = $segment;
        }

        if ($segments === []) {
            throw new RuntimeException('Path media tidak valid.');
        }

        return implode('/', $segments);
    }

    public function storageRoot(): string
    {
        return Storage::disk(self::DISK)->path('');
    }

    protected function normalizeDirectory(string $directory): string
    {
        return trim($this->normalizeRelativePath($directory), '/');
    }

    protected function normalizeFilename(string $filename): string
    {
        $filename = trim(str_replace(['\\', '/'], '', $filename));

        if (
            $filename === ''
            || $filename === '.'
            || $filename === '..'
            || str_contains($filename, "\0")
        ) {
            throw new RuntimeException('Nama file media tidak valid.');
        }

        return $filename;
    }

    protected function safeFilename(string $base, string $extension): string
    {
        $base = Str::slug($base);

        if ($base === '') {
            $base = 'media';
        }

        $extension = preg_replace('/[^A-Za-z0-9]+/', '', $extension) ?: 'bin';

        return $base . '-' . Str::lower(Str::random(12)) . '.' . strtolower($extension);
    }

    protected function comparablePath(string $path): string
    {
        $path = rtrim($path, '\\/');

        return DIRECTORY_SEPARATOR === '\\'
            ? strtolower($path)
            : $path;
    }

    /** Alias kompatibilitas untuk modul P9-D/E/F. */
    public function normalize(?string $path): ?string
    {
        return $this->normalizeLegacyPath($path);
    }

    /** Alias store dengan nama eksplisit untuk upload controller. */
    public function storeUploadedAs(
        UploadedFile $file,
        string $directory,
        string $filename
    ): string {
        return $this->store($file, $directory, $filename);
    }

    /** Menyalin file lokal (mis. hasil ekstrak ZIP foto) ke disk public. */
    public function putFileFromPath(string $relativePath, string $sourcePath): string
    {
        if (!File::isFile($sourcePath)) {
            throw new RuntimeException('File sumber media tidak ditemukan.');
        }

        $relative = $this->normalizeRelativePath($relativePath);
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new RuntimeException('File sumber media tidak dapat dibuka.');
        }

        try {
            $ok = Storage::disk(self::DISK)->put($relative, $stream);
        } finally {
            fclose($stream);
        }

        if (!$ok) {
            throw new RuntimeException('Gagal menyalin media lokal.');
        }

        return $relative;
    }

    /** Response browser tanpa ketergantungan public/storage symlink. */
    public function response(?string $path)
    {
        $absolute = $this->path($path);
        abort_unless($absolute !== null && File::isFile($absolute), 404);

        return response()->file($absolute, [
            'Content-Type' => $this->mimeType($path) ?? 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Mengubah URL media legacy di HTML rich-text menjadi /media/.
     * Data-URL (gambar_soal offline) dan URL eksternal tidak disentuh.
     */
    public function normalizeHtmlMediaUrls(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return preg_replace_callback(
            "#(<img\\b[^>]*\\bsrc=[\"'])([^\"']+)([\"'])#i",
            function (array $m): string {
                $src = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');

                if (str_starts_with($src, 'data:')) {
                    return $m[0];
                }

                $path = parse_url($src, PHP_URL_PATH);
                if (!is_string($path)) {
                    return $m[0];
                }

                if (!preg_match('#/(?:storage|media)/(.+)$#i', $path, $match)) {
                    return $m[0];
                }

                $relative = $this->normalizeLegacyPath($match[1]);
                $url = $relative ? $this->url($relative) : null;

                return $url ? $m[1] . e($url) . $m[3] : $m[0];
            },
            $html
        ) ?? $html;
    }

}

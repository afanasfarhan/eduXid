<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

class PatchPackageValidator
{
    private const MAX_FILES = 5000;
    private const MAX_UNCOMPRESSED_BYTES = 536870912; // 512 MB

    public function assertValidVersion(string $version): void
    {
        $version = trim($version);

        if (preg_match('/^\d+\.\d+\.\d+(-[a-zA-Z0-9.]+)?$/', $version) !== 1) {
            throw new RuntimeException("Versi patch tidak valid: {$version}");
        }
    }

    public function inspect(string $zipPath, string $version): array
    {
        $this->assertValidVersion($version);

        if (!is_file($zipPath) || !is_readable($zipPath)) {
            throw new RuntimeException('File ZIP patch tidak ditemukan atau tidak dapat dibaca.');
        }

        $zip = new ZipArchive();
        $open = $zip->open($zipPath);

        if ($open !== true) {
            throw new RuntimeException("ZIP patch tidak valid. Kode ZipArchive: {$open}");
        }

        try {
            if ($zip->numFiles < 1) {
                throw new RuntimeException('ZIP patch kosong.');
            }

            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('ZIP patch berisi terlalu banyak file.');
            }

            $entries = [];
            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);

                if (!$stat || !isset($stat['name'])) {
                    throw new RuntimeException('Metadata entry ZIP tidak dapat dibaca.');
                }

                $name = $this->normalizeEntry((string) $stat['name']);

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $this->assertSafeEntry($name);

                $size = (int) ($stat['size'] ?? 0);

                if ($size < 0) {
                    throw new RuntimeException("Ukuran entry ZIP tidak valid: {$name}");
                }

                $total += $size;

                if ($total > self::MAX_UNCOMPRESSED_BYTES) {
                    throw new RuntimeException(
                        'Ukuran hasil ekstraksi patch melebihi batas 512 MB.'
                    );
                }

                $entries[] = $name;
            }

$normalizedVersion = $this->normalizeVersion($version);

            $required = "app/Updates/Patches/Patch_{$normalizedVersion}.php";

            if (!in_array($required, $entries, true)) {
                throw new RuntimeException(
                    "ZIP tidak memuat file patch wajib: {$required}"
                );
            }

            return [
                'sha256' => hash_file('sha256', $zipPath),
                'files' => count($entries),
                'uncompressed_bytes' => $total,
                'entries' => $entries,
                'required_patch_file' => $required,
            ];
        } finally {
            $zip->close();
        }
    }

public function normalizeVersion(string $version): string
{
    $this->assertValidVersion($version);

    $normalized = trim(
        (string) preg_replace(
            '/[^A-Za-z0-9]+/',
            '_',
            trim($version)
        ),
        '_'
    );

    if ($normalized === '') {
        throw new RuntimeException(
            "Versi patch tidak dapat dinormalisasi: {$version}"
        );
    }

    return $normalized;
}

    public function assertSafeEntry(string $entry): void
    {
        $entry = $this->normalizeEntry($entry);

        if ($entry === '' || str_contains($entry, "\0")) {
            throw new RuntimeException('ZIP memiliki nama entry tidak valid.');
        }

        if (
            str_starts_with($entry, '/')
            || preg_match('/^[A-Za-z]:\//', $entry) === 1
        ) {
            throw new RuntimeException("ZIP memiliki path absolut: {$entry}");
        }

        foreach (explode('/', $entry) as $part) {
            if ($part === '..') {
                throw new RuntimeException("ZIP memiliki path traversal: {$entry}");
            }
        }

        $lower = strtolower($entry);

        $blockedExact = [
            '.env',
            'artisan',
            'composer.phar',
        ];

        if (in_array($lower, $blockedExact, true) || str_starts_with($lower, '.env.')) {
            throw new RuntimeException("Patch mencoba menulis file terlarang: {$entry}");
        }

        $blockedPrefixes = [
            'storage/',
            'vendor/',
            'node_modules/',
            '.git/',
            'bootstrap/cache/',
        ];

        foreach ($blockedPrefixes as $prefix) {
            if (str_starts_with($lower, $prefix)) {
                throw new RuntimeException("Patch mencoba menulis lokasi terlarang: {$entry}");
            }
        }
    }

    public function normalizeEntry(string $entry): string
    {
        $entry = str_replace('\\', '/', trim($entry));

        while (str_starts_with($entry, './')) {
            $entry = substr($entry, 2);
        }

        return $entry;
    }
}

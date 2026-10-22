<?php

declare(strict_types=1);

namespace App\Services\Students;

use App\Services\LocalMediaService;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * eduXid V21R49 — ZIP foto siswa, pencocokan NISN aman termasuk nama/folder berawalan nol.
 * NISN adalah identitas teks 10 digit. Jangan cast ke int (nol awal penting).
 * Tidak mengirim foto atau NISN ke Cloud.
 */
final class StudentPhotoZipImporter
{
    private const MAX_ENTRIES = 5000;
    private const MAX_FILE_BYTES = 20 * 1024 * 1024;
    private const MAX_TOTAL_BYTES = 500 * 1024 * 1024;
    private const MAX_ZIP_PATH_DEPTH = 12;
    private const MAX_FAILURES_SHOWN = 80;

    public function __construct(private readonly LocalMediaService $media)
    {
    }

    /**
     * Accept legacy SQLite values that lost leading zeroes (e.g. 123456789),
     * but never perform numeric conversion on a genuine 10-digit NISN.
     */
    public static function canonicalNisn(string $value): ?string
    {
        $value = trim($value);
        if (!preg_match('/^[0-9]{8,10}$/D', $value)) {
            return null;
        }
        return str_pad($value, 10, '0', STR_PAD_LEFT);
    }

    /**
     * Extract only isolated 8–10 digit tokens. Prefer exact 10-digit NISN over
     * legacy shorter numeric values; do not match substrings of longer numbers.
     * Never infer identity from a student's name or the photograph contents.
     *
     * Examples: IMG_0123456789.jpg, 0123456789 - Nama.png,
     *             sekolah/0123456789/IMG_0001.jpg (folder fallback).
     */
    public static function candidatesInName(string $segment): array
    {
        $stem = trim((string) pathinfo($segment, PATHINFO_FILENAME));
        if (!preg_match_all('/(?<![0-9])([0-9]{8,10})(?![0-9])/u', $stem, $matches)) {
            return [];
        }
        $exact = [];
        $legacy = [];
        foreach ($matches[1] as $value) {
            $normalized = self::canonicalNisn((string) $value);
            if ($normalized === null) {
                continue;
            }
            if (strlen((string) $value) === 10) {
                $exact[$normalized] = true;
            } else {
                $legacy[$normalized] = true;
            }
        }
        return array_keys($exact ?: $legacy);
    }

    public static function nisnFromFilename(string $filename): ?string
    {
        $candidates = self::candidatesInName($filename);
        return count($candidates) === 1 ? $candidates[0] : null;
    }

    /**
     * Only a unique local roster match is accepted. This also permits NISN in
     * a ZIP folder when its photo filenames are generic (IMG_0001.jpg).
     * Multiple possible students are always rejected, never guessed.
     *
     * @return array{0:?string,1:string}
     */
    private static function resolveArchiveNisn(string $path, array $students, array $ambiguous): array
    {
        $parts = explode('/', $path);
        $filename = array_pop($parts);
        $segments = array_merge([$filename], array_reverse($parts));
        $sawDigits = false;
        foreach ($segments as $segment) {
            $candidates = self::candidatesInName($segment);
            if (!$candidates) {
                continue;
            }
            $sawDigits = true;
            $matches = [];
            $ambiguousMatch = false;
            foreach ($candidates as $candidate) {
                if (isset($ambiguous[$candidate])) {
                    $ambiguousMatch = true;
                } elseif (isset($students[$candidate])) {
                    $matches[$candidate] = true;
                }
            }
            if ($ambiguousMatch || count($matches) > 1) {
                return [null, 'NISN ganda/ambigu; foto tidak boleh dipasangkan sembarangan.'];
            }
            if (count($matches) === 1) {
                return [(string) array_key_first($matches), ''];
            }
        }
        return [null, $sawDigits
            ? 'NISN pada nama file/folder tidak ditemukan di database lokal.'
            : 'Tidak ditemukan NISN 8–10 digit pada nama file maupun folder ZIP.'];
    }

    /** @return array{total:int,saved:int,skipped:int,ignored:int,failures:array,more_failures:int,reasons:array} */
    public function import(string $archivePath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Ekstensi ZIP PHP belum diaktifkan.');
        }
        $zip = new ZipArchive();
        if ($zip->open($archivePath, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('File ZIP foto siswa tidak dapat dibuka.');
        }
        $report = ['total' => 0, 'saved' => 0, 'skipped' => 0, 'ignored' => 0, 'failures' => [], 'more_failures' => 0, 'reasons' => []];

        try {
            if ($zip->numFiles > self::MAX_ENTRIES) {
                throw new RuntimeException('ZIP berisi lebih dari 5.000 entri.');
            }

            // Build a collision-aware lookup. If two student rows normalize to
            // the same NISN, never choose one of them arbitrarily.
            $students = [];
            $ambiguous = [];
            foreach (DB::table('students')->whereNotNull('nisn')->get(['id', 'nisn']) as $student) {
                $nisn = self::canonicalNisn((string) $student->nisn);
                if ($nisn === null) {
                    continue;
                }
                if (isset($students[$nisn])) {
                    $ambiguous[$nisn] = true;
                    continue;
                }
                $students[$nisn] = (int) $student->id;
            }

            $seenSuccessfully = [];
            $totalExpanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawPath = (string) $zip->getNameIndex($i);
                $path = str_replace('\\', '/', $rawPath);
                if ($path === '' || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path)
                    || str_contains($path, "\0") || count(explode('/', $path)) > self::MAX_ZIP_PATH_DEPTH
                    || in_array('..', explode('/', $path), true)) {
                    throw new RuntimeException('ZIP berisi path yang tidak aman.');
                }

                $opsys = 0;
                $attrs = 0;
                if ($zip->getExternalAttributesIndex($i, $opsys, $attrs)) {
                    $type = ($attrs >> 16) & 0170000;
                    if ($type === 0120000 || ($type !== 0 && $type !== 0100000 && $type !== 0040000)) {
                        throw new RuntimeException('ZIP berisi symlink atau jenis file yang tidak aman.');
                    }
                }
                if (str_ends_with($path, '/')) {
                    continue;
                }
                $report['total']++;
                $filename = basename($path);
                // Ignore operating-system metadata explicitly, not silently.
                if (str_starts_with($path, '__MACOSX/') || str_starts_with($filename, '._')
                    || in_array(strtolower($filename), ['.ds_store', 'thumbs.db', 'desktop.ini'], true)) {
                    $report['ignored']++;
                    continue;
                }
                $ext = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
                $stat = $zip->statIndex($i);
                if (!is_array($stat)) {
                    $this->skip($report, $filename, 'Metadata ZIP tidak terbaca.');
                    continue;
                }
                $expanded = (int) ($stat['size'] ?? -1);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                if ($expanded < 0) {
                    $this->skip($report, $filename, 'Ukuran file tidak terbaca.');
                    continue;
                }
                $totalExpanded += $expanded;
                if ($totalExpanded > self::MAX_TOTAL_BYTES) {
                    throw new RuntimeException('Total isi ZIP melebihi batas keamanan 500 MB.');
                }
                if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    $this->skip($report, $filename, 'Format bukan JPG, PNG, atau WebP.');
                    continue;
                }
                if ($expanded === 0 || $expanded > self::MAX_FILE_BYTES
                    || ($expanded > 1024 * 1024 && $expanded > max(1, $compressed) * 200)) {
                    $this->skip($report, $filename, 'Ukuran atau rasio kompresi tidak aman.');
                    continue;
                }
                [$nisn, $resolveReason] = self::resolveArchiveNisn($path, $students, $ambiguous);
                if ($nisn === null) {
                    $this->skip($report, $path, $resolveReason);
                    continue;
                }
                if (isset($seenSuccessfully[$nisn])) {
                    $this->skip($report, $filename, 'Foto untuk NISN yang sama sudah diproses dalam ZIP ini.');
                    continue;
                }

                $tmp = tempnam(storage_path('app'), 'eduxid_foto_');
                if ($tmp === false) {
                    throw new RuntimeException('Tidak dapat membuat file sementara untuk foto.');
                }
                try {
                    $stream = $zip->getStream($rawPath);
                    if (!is_resource($stream)) {
                        $this->skip($report, $filename, 'Data gambar dalam ZIP tidak dapat dibaca.');
                        continue;
                    }
                    try {
                        $destination = @fopen($tmp, 'wb');
                        if ($destination === false) {
                            throw new RuntimeException('Media sementara tidak dapat ditulis.');
                        }
                        try {
                            $copied = stream_copy_to_stream($stream, $destination, self::MAX_FILE_BYTES + 1);
                        } finally {
                            fclose($destination);
                        }
                        // feof() on ZIP streams may remain false until another read.
                        // Exact decompressed byte count is the authoritative check.
                        if ($copied === false || $copied !== $expanded) {
                            $this->skip($report, $filename, 'Data gambar dalam ZIP terpotong atau tidak lengkap.');
                            continue;
                        }
                    } finally {
                        fclose($stream);
                    }
                    $image = @getimagesize($tmp);
                    $mime = is_array($image) ? strtolower((string) ($image['mime'] ?? '')) : '';
                    $actualExt = match ($mime) {
                        'image/jpeg' => 'jpg',
                        'image/png' => 'png',
                        'image/webp' => 'webp',
                        default => null,
                    };
                    if ($actualExt === null || (int) ($image[0] ?? 0) < 1 || (int) ($image[1] ?? 0) < 1
                        || (int) ($image[0] ?? 0) * (int) ($image[1] ?? 0) > 60000000) {
                        $this->skip($report, $path, 'Gambar tidak valid atau dimensi terlalu besar.');
                        continue;
                    }

                    $relative = 'photos/siswa/' . $nisn . '.' . $actualExt;
                    $this->media->putFileFromPath($relative, $tmp);
                    DB::table('students')->where('id', $students[$nisn])->update([
                        'foto_path' => $relative,
                        'updated_at' => now(),
                    ]);
                    $seenSuccessfully[$nisn] = true;
                    $report['saved']++;
                } catch (Throwable $e) {
                    // Process the next photo rather than silently discarding
                    // the rest of the ZIP due to one unreadable image.
                    report($e);
                    $this->skip($report, $filename, 'Gagal menyimpan atau memproses foto.');
                } finally {
                    @unlink($tmp);
                }
            }
        } finally {
            $zip->close();
        }
        return $report;
    }

    private function skip(array &$report, string $filename, string $reason): void
    {
        $report['skipped']++;
        $report['reasons'][$reason] = ($report['reasons'][$reason] ?? 0) + 1;
        if (count($report['failures']) < self::MAX_FAILURES_SHOWN) {
            $report['failures'][] = ['file' => (function_exists('mb_substr') ? mb_substr($filename, 0, 100) : substr($filename, 0, 100)), 'reason' => $reason];
        } else {
            $report['more_failures']++;
        }
    }
}

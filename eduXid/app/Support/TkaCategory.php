<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * PGC Kategori resmi TKA direpresentasikan sebagai serangkaian soal
 * benar_salah yang terikat pada tka_group_code yang sama. Setiap
 * pernyataan = satu slot LJK A/B dan satu unit koreksi existing.
 * Cara ini tidak mengubah kontrak kunci dan hasil ujian yang sudah berjalan.
 */
final class TkaCategory
{
    public static function statements(mixed $raw): array
    {
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) $raw = $decoded;
            else {
                $raw = preg_split('/\r\n|\r|\n/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
                $raw = array_map(static function ($line) {
                    $parts = explode('|', trim((string)$line), 2);
                    return ['key' => trim((string)($parts[0] ?? '')), 'text' => trim((string)($parts[1] ?? ''))];
                }, $raw ?: []);
            }
        }
        if (!is_array($raw) || count($raw) < 2 || count($raw) > 10) {
            throw new InvalidArgumentException('Soal TKA kategori wajib memiliki 2–10 pernyataan.');
        }
        $out = [];
        foreach (array_values($raw) as $index => $statement) {
            if (!is_array($statement)) throw new InvalidArgumentException('Pernyataan kategori tidak valid.');
            $text = trim((string)($statement['text'] ?? $statement['pernyataan'] ?? ''));
            $key = strtoupper(trim((string)($statement['key'] ?? $statement['kunci'] ?? '')));
            $key = match ($key) {
                'B', 'BENAR', 'TRUE' => 'B',
                'S', 'SALAH', 'FALSE' => 'S',
                default => '',
            };
            if ($key === '' || $text === '' || strlen($text) > 6000) {
                throw new InvalidArgumentException('Pernyataan '.($index+1).' wajib berisi teks dan kunci B/S.');
            }
            $out[] = ['text' => $text, 'key' => $key];
        }
        return $out;
    }
}

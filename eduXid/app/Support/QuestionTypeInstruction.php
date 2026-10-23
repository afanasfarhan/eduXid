<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Petunjuk soal pada lapisan tampilan, tidak mengubah teks pertanyaan,
 * kunci, jawaban siswa, maupun data Bank Soal.
 */
final class QuestionTypeInstruction
{
    public const MAJEMUK = 'Untuk soal ini, Anda diperbolehkan memilih lebih dari satu jawaban.';
    public const BENAR_SALAH = 'Soal ini adalah pernyataan benar salah. Jawab A jika pernyataan benar, dan jawab B jika pernyataan salah.';

    public static function forType(?string $type, bool $tkaCategory = false): ?string
    {
        // TKA kategori memakai tabel Benar/Salah dan kode B/S, BUKAN tombol A/B.
        if ($tkaCategory) {
            return null;
        }

        return match (strtolower(trim((string) $type))) {
            'majemuk', 'pilihan_majemuk', 'multiple_choice_complex' => self::MAJEMUK,
            'benar_salah', 'true_false' => self::BENAR_SALAH,
            default => null,
        };
    }
}

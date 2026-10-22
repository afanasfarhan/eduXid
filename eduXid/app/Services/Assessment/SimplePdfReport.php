<?php

namespace App\Services\Assessment;

/**
 * Fallback PDF tanpa dependency eksternal.
 * Dipakai hanya bila Dompdf tidak tersedia pada instalasi.
 */
class SimplePdfReport
{
    public function __construct(private readonly PdfMathRenderer $math) {}

    private const PAGE_W = 842.0; // A4 landscape
    private const PAGE_H = 595.0;
    private const MARGIN_X = 34.0;
    private const TOP_Y = 558.0;
    private const BOTTOM_Y = 34.0;

    public function render(array $analysis): string
    {
        $lines = [];
        $meta = $analysis['meta'] ?? [];
        $summary = $analysis['summary'] ?? [];

        $this->add($lines, 'LAPORAN ANALISIS BUTIR SOAL', 'title');
        $this->add($lines, (string) ($meta['school_name'] ?? '-'), 'heading');
        $this->add($lines, 'NPSN: ' . ($meta['school_npsn'] ?? '-'), 'small');
        $this->add($lines, '', 'normal');

        $this->add($lines, 'Identitas', 'section');
        $this->add($lines, 'Nama Guru      : ' . ($meta['teacher_name'] ?? '-'));
        $this->add($lines, 'NIP/NIK        : ' . ($meta['teacher_nip'] ?? '-'));
        $this->add($lines, 'Mata Pelajaran : ' . ($meta['subject_name'] ?? '-'));
        $this->add($lines, 'Tingkat Kelas  : ' . ($meta['grade_level'] ?? '-'));
        $this->add($lines, 'Paket Ujian    : ' . ($meta['exam_title'] ?? '-'));
        $this->add($lines, 'Sumber Analisis: ' . ($analysis['source_label'] ?? '-'));
        $this->add($lines, 'Tahun/Semester : ' . ($meta['academic_year'] ?? '-') . ' / ' . ($meta['semester'] ?? '-'));
        $this->add($lines, '', 'normal');

        $this->add($lines, 'Ringkasan', 'section');
        $this->add($lines, sprintf(
            'Peserta: %d | CBT: %d | LJK: %d | Soal: %d | Dedup: %d',
            (int) ($summary['participant_count'] ?? 0),
            (int) ($summary['cbt_count'] ?? 0),
            (int) ($summary['ljk_count'] ?? 0),
            (int) ($summary['question_count'] ?? 0),
            (int) ($summary['deduped_count'] ?? 0)
        ), 'table');
        $this->add($lines, sprintf(
            'Rata-rata: %s | Median: %s | Min: %s | Maks: %s | SD: %s',
            $this->num($summary['mean_score'] ?? null, 2),
            $this->num($summary['median_score'] ?? null, 2),
            $this->num($summary['min_score'] ?? null, 2),
            $this->num($summary['max_score'] ?? null, 2),
            $this->num($summary['stddev_score'] ?? null, 2)
        ), 'table');
        $this->add($lines, 'Reliabilitas alpha: ' . $this->num($summary['reliability_alpha'] ?? null, 3)
            . ' (' . ($summary['reliability_label'] ?? '-') . ')', 'table');
        $this->add($lines, (string) ($summary['sample_note'] ?? ''), 'small');
        $this->add($lines, '', 'normal');

        $this->add($lines, 'Analisis per Butir', 'section');
        foreach ($analysis['items'] ?? [] as $item) {
            $title = sprintf(
                'No %d | p=%s %s | D=%s %s | rpb=%s | alpha-if-del=%s | %s',
                (int) ($item['order'] ?? 0),
                $this->num($item['difficulty_index'] ?? null, 3),
                $item['difficulty_label'] ?? '-',
                $this->num($item['discrimination_index'] ?? null, 3),
                $item['discrimination_label'] ?? '-',
                $this->num($item['point_biserial'] ?? null, 3),
                $this->num($item['alpha_if_deleted'] ?? null, 3),
                $item['status'] ?? '-'
            );
            $this->add($lines, $title, 'item');
            $this->add($lines, 'Jenis: ' . ($item['type'] ?? '-') . ' | Level: ' . ($item['cognitive_level'] ?? '-')
                . ' | Kunci: ' . ($item['answer_key'] ?? '-'), 'small');
            if (trim((string) ($item['material'] ?? '')) !== '') {
                $this->addWrapped($lines, 'Materi: ' . $this->math->toPlainText((string) $item['material']), 'small', 135);
            }
            if (trim((string) ($item['indicator'] ?? '')) !== '') {
                $this->addWrapped($lines, 'Indikator: ' . $this->math->toPlainText((string) $item['indicator']), 'small', 135);
            }
            if (trim((string) ($item['stem'] ?? '')) !== '') {
                $this->addWrapped($lines, 'Soal: ' . $this->math->toPlainText((string) $item['stem']), 'small', 135);
            }
            $this->add($lines, sprintf(
                'Benar: %d/%d | Kosong: %d | Dasar keputusan: %s',
                (int) ($item['correct_count'] ?? 0),
                (int) ($item['participant_count'] ?? 0),
                (int) ($item['blank_count'] ?? 0),
                (string) ($item['recommendation'] ?? '-')
            ), 'small');
            $this->addWrapped(
                $lines,
                'Rekomendasi perbaikan: ' . (string) ($item['recommendation_improvement'] ?? '-'),
                'small',
                135
            );

            if (!empty($item['options'])) {
                $parts = [];
                foreach ($item['options'] as $opt) {
                    $parts[] = sprintf(
                        '%s%s=%d (%.1f%%; U%d/L%d; %s)',
                        ($opt['key'] ?? '?') . (
                            trim((string) ($opt['text'] ?? '')) !== ''
                                ? ':' . $this->math->toPlainText((string) $opt['text'])
                                : ''
                        ),
                        !empty($opt['is_key']) ? '*' : '',
                        (int) ($opt['count'] ?? 0),
                        ((float) ($opt['share'] ?? 0)) * 100,
                        (int) ($opt['upper_count'] ?? 0),
                        (int) ($opt['lower_count'] ?? 0),
                        $opt['status'] ?? '-'
                    );
                }
                $this->addWrapped($lines, 'Opsi: ' . implode(' | ', $parts), 'table', 140);
            }
            $this->add($lines, '', 'normal');
        }

        $this->add($lines, 'Analisis Tingkat Kognitif', 'section');
        foreach ($analysis['cognitive'] ?? [] as $row) {
            $this->add($lines, sprintf(
                '%s | %d soal | rata-rata benar %s%%',
                $row['label'] ?? '-',
                (int) ($row['items'] ?? 0),
                $this->num($row['mean_correct_pct'] ?? null, 1)
            ), 'table');
        }
        $this->add($lines, '', 'normal');

        $this->add($lines, 'Analisis Indikator', 'section');
        foreach ($analysis['indicators'] ?? [] as $row) {
            $this->addWrapped($lines, sprintf(
                '%s | %d soal | rata-rata benar %s%%',
                $row['label'] ?? '-',
                (int) ($row['items'] ?? 0),
                $this->num($row['mean_correct_pct'] ?? null, 1)
            ), 'table', 140);
        }

        $this->add($lines, '', 'normal');
        $this->add($lines, 'Penjelasan Metode Analisis', 'section');
        $this->addWrapped($lines, 'Kerangka: Classical Test Theory (CTT). Respons final peserta dari CBT dan/atau LJK dianalisis pada Paket Ujian yang sama. Pada sumber gabungan, peserta dideduplikasi agar tidak dihitung dua kali.', 'small', 140);
        $this->addWrapped($lines, 'Tingkat Kesukaran (p): p = B/N, B adalah jumlah jawaban benar dan N respons valid. Pedoman eduXid: p <= 0,29 Sukar; 0,30-0,70 Sedang; p >= 0,71 Mudah.', 'small', 140);
        $this->addWrapped($lines, 'Daya Pembeda (D): D = p_atas - p_bawah menggunakan sekitar 27% kelompok skor atas dan 27% kelompok skor bawah. D negatif merupakan peringatan untuk memeriksa kunci, redaksi, atau penskoran.', 'small', 140);
        $this->addWrapped($lines, 'Corrected item-total / rpb: korelasi Pearson antara skor item 0/1 dan skor total setelah item tersebut dikeluarkan dari total. Nilai positif tinggi menunjukkan item konsisten dengan kemampuan yang diukur.', 'small', 140);
        $this->addWrapped($lines, 'Distraktor: dianalisis dari frekuensi pemilihan, persentase, kelompok atas/bawah, dan korelasi pilihan. Distraktor yang tidak dipilih, sangat jarang dipilih, atau lebih menarik kelompok atas ditandai untuk ditinjau.', 'small', 140);
        $this->addWrapped($lines, 'Reliabilitas: Cronbach Alpha = k/(k-1) x [1 - jumlah varians item / varians skor total]. Alpha jika item dihapus dihitung ulang tanpa item tersebut sebagai sinyal tambahan, bukan satu-satunya dasar keputusan.', 'small', 140);
        $this->addWrapped($lines, 'Indikator dan level kognitif: item dikelompokkan lalu dihitung rata-rata proporsi benar untuk membantu menentukan area penguatan/remedial.', 'small', 140);
        $this->addWrapped($lines, 'Batasan: kategori statistik merupakan pedoman psikometri yang dapat dikonfigurasi, bukan angka wajib regulasi. Sampel kecil kurang stabil; keputusan akhir tetap mempertimbangkan validitas isi, indikator, level kognitif, bahasa, tujuan tes, dan pertimbangan profesional guru.', 'small', 140);
        $this->add($lines, 'Dibuat: ' . ($meta['generated_at'] ?? date('d-m-Y H:i')), 'small');

        return $this->buildPdf($lines);
    }

    private function add(array &$lines, string $text, string $style = 'normal'): void
    {
        $lines[] = ['text' => $text, 'style' => $style];
    }

    private function addWrapped(array &$lines, string $text, string $style, int $maxChars): void
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        if ($text === '') {
            $this->add($lines, '', $style);
            return;
        }

        foreach ($this->wrap($text, $maxChars) as $line) {
            $this->add($lines, $line, $style);
        }
    }

    private function wrap(string $text, int $maxChars): array
    {
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = $current === '' ? $word : $current . ' ' . $word;
            $length = function_exists('mb_strlen') ? mb_strlen($candidate, 'UTF-8') : strlen($candidate);
            if ($length <= $maxChars) {
                $current = $candidate;
            } else {
                if ($current !== '') $lines[] = $current;
                $current = $word;
            }
        }
        if ($current !== '') $lines[] = $current;
        return $lines ?: [''];
    }

    private function buildPdf(array $lines): string
    {
        $pages = [];
        $current = [];
        $heightUsed = 0.0;

        foreach ($lines as $line) {
            [$font, $size, $leading] = $this->style($line['style']);
            if ($heightUsed + $leading > (self::TOP_Y - self::BOTTOM_Y)) {
                $pages[] = $current;
                $current = [];
                $heightUsed = 0.0;
            }
            $current[] = $line;
            $heightUsed += $leading;
        }
        if ($current !== [] || $pages === []) $pages[] = $current;

        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $objects[5] = '<< /Type /Font /Subtype /Type1 /BaseFont /Courier /Encoding /WinAnsiEncoding >>';

        $pageRefs = [];
        $nextObj = 6;

        foreach ($pages as $pageIndex => $pageLines) {
            $contentObj = $nextObj++;
            $pageObj = $nextObj++;
            $pageRefs[] = $pageObj . ' 0 R';

            $y = self::TOP_Y;
            $commands = [];

            foreach ($pageLines as $line) {
                [$font, $size, $leading] = $this->style($line['style']);
                $text = $this->pdfText((string) $line['text']);
                if ($text !== '') {
                    $commands[] = sprintf(
                        "BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET",
                        $font,
                        $size,
                        self::MARGIN_X,
                        $y,
                        $text
                    );
                }
                $y -= $leading;
            }

            $footer = 'eduXid - Analisis Butir Soal - Halaman ' . ($pageIndex + 1) . '/' . count($pages);
            $commands[] = sprintf(
                "BT /F1 7 Tf %.2F 18 Td (%s) Tj ET",
                self::MARGIN_X,
                $this->pdfText($footer)
            );

            $stream = implode("\n", $commands);
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageObj] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.0F %.0F] /Resources << /Font << /F1 3 0 R /F2 4 0 R /F3 5 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_W,
                self::PAGE_H,
                $contentObj
            );
        }

        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $pageRefs) . '] /Count ' . count($pageRefs) . ' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];

        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xref = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1; $i <= $max; $i++) {
            $offset = $offsets[$i] ?? 0;
            $pdf .= sprintf("%010d 00000 %s \n", $offset, isset($objects[$i]) ? 'n' : 'f');
        }

        $pdf .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xref . "\n%%EOF";

        return $pdf;
    }

    private function style(string $style): array
    {
        return match ($style) {
            'title' => ['F2', 16.0, 21.0],
            'heading' => ['F2', 11.0, 15.0],
            'section' => ['F2', 10.0, 15.0],
            'item' => ['F2', 8.5, 12.0],
            'table' => ['F3', 7.2, 10.0],
            'small' => ['F1', 7.2, 10.0],
            default => ['F1', 8.2, 11.0],
        };
    }

    private function pdfText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(["\r", "\n", "\t"], ' ', $text);

        $encoded = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if ($encoded === false) {
            $encoded = preg_replace('/[^\x20-\x7E]/', '?', $text) ?? $text;
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    private function num(mixed $value, int $precision): string
    {
        return is_numeric($value) ? number_format((float) $value, $precision, ',', '.') : '-';
    }
}

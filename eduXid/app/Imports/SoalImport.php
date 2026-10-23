<?php

namespace App\Imports;

use App\Models\Subject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use RuntimeException;
use App\Support\TkaCategory;
use App\Support\QuestionHtmlSanitizer;
use Illuminate\Support\Str;

class SoalImport implements ToCollection, WithHeadingRow
{
    private int $savedImageCount = 0;
    private array $usedImageRows = [];

    /** @param array<int,string> $imagesByRow Data URL gambar dibaca langsung dari OOXML. */
    public function __construct(protected int $teacherId, private array $imagesByRow = [], private bool $repairImages = false)
    {
    }

    public function savedImageCount(): int
    {
        return $this->savedImageCount;
    }

    private function activeSemesterId(): ?string
    {
        $sessionSemester = trim((string) session('semester_aktif'));

        if ($sessionSemester !== '') {
            return $sessionSemester;
        }

        $semester = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $this->teacherId)
            ->max('homerooms.semester_id');

        return $semester !== null ? (string) $semester : null;
    }

    private function normalizeGradeLevel(mixed $raw): ?int
    {
        $value = strtoupper(trim((string) $raw));

        if ($value === '') {
            return null;
        }

        if (preg_match('/(?:^|\D)(10|11|12)(?:\D|$)/', $value, $matches)) {
            return (int) $matches[1];
        }

        $value = preg_replace('/\s+/', ' ', $value);

        return match ($value) {
            'X', 'KELAS X', 'TINGKAT X' => 10,
            'XI', 'KELAS XI', 'TINGKAT XI' => 11,
            'XII', 'KELAS XII', 'TINGKAT XII' => 12,
            default => null,
        };
    }

    /**
     * Tingkat yang benar-benar diampu guru untuk satu mapel pada
     * semester aktif. Nilai selalu unik dan terurut.
     */
    private function allowedGradesForSubject(int $subjectId): array
    {
        $semesterId = $this->activeSemesterId();

        $query = DB::table('teaching_assignments')
            ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
            ->where('teaching_assignments.teacher_id', $this->teacherId)
            ->where('teaching_assignments.subject_id', $subjectId)
            ->select('homerooms.tingkat_pendidikan');

        if ($semesterId !== null) {
            $query->where('homerooms.semester_id', $semesterId);
        }

        $grades = [];

        foreach ($query->get() as $row) {
            $grade = $this->normalizeGradeLevel($row->tingkat_pendidikan ?? null);

            if ($grade !== null) {
                $grades[$grade] = $grade;
            }
        }

        ksort($grades, SORT_NUMERIC);

        return array_values($grades);
    }

    public function collection(Collection $rows): void
    {
        foreach ($rows as $index => $row) {
            $excelRow = $index + 2;
            // Mode perbaikan: hanya perbarui gambar baris yang memang memiliki gambar.
            // Jangan menambah soal, mengganti kunci, atau mengubah ID Paket Ujian.
            if ($this->repairImages && !isset($this->imagesByRow[$excelRow])) {
                continue;
            }
            // Template Excel dapat memiliki baris yang hanya diformat;
            // jangan menganggap baris kosong tersebut sebagai soal baru.
            if ($row->filter(static fn ($value) => $value !== null && trim((string) $value) !== '')->isEmpty()
                && !isset($this->imagesByRow[$excelRow])) {
                continue;
            }
            $mapelNama = trim((string) ($row['mata_pelajaran'] ?? ''));

            if ($mapelNama === '') {
                throw new RuntimeException("Baris {$excelRow}: Mata Pelajaran wajib diisi.");
            }

            $subject = Subject::query()
                ->where('nama_mata_pelajaran', $mapelNama)
                ->first();

            if (!$subject) {
                $subject = Subject::query()
                    ->where('nama_mata_pelajaran', 'like', '%' . $mapelNama . '%')
                    ->first();
            }

            if (!$subject) {
                throw new RuntimeException(
                    "Baris {$excelRow}: Mata pelajaran '{$mapelNama}' tidak ditemukan."
                );
            }

            $allowedGrades = $this->allowedGradesForSubject((int) $subject->id);

            if ($allowedGrades === []) {
                throw new RuntimeException(
                    "Baris {$excelRow}: Anda tidak memiliki penugasan {$subject->nama_mata_pelajaran} pada semester aktif."
                );
            }

            $grade = $this->normalizeGradeLevel($row['tingkat_kelas'] ?? null);

            /*
             * Kompatibilitas template lama:
             * bila kolom Tingkat Kelas belum ada DAN guru hanya mengajar
             * mapel tersebut pada satu tingkat, tingkat dapat diinferensikan.
             */
            if ($grade === null && count($allowedGrades) === 1) {
                $grade = (int) $allowedGrades[0];
            }

            if ($grade === null) {
                throw new RuntimeException(
                    "Baris {$excelRow}: Tingkat Kelas wajib diisi (10/11/12). "
                    . 'Unduh template Bank Soal terbaru.'
                );
            }

            if (!in_array($grade, $allowedGrades, true)) {
                throw new RuntimeException(
                    "Baris {$excelRow}: Kelas {$grade} tidak sesuai dengan penugasan "
                    . "Anda untuk {$subject->nama_mata_pelajaran} pada semester aktif."
                );
            }

            $jenisSoal = strtolower(trim((string) ($row['jenis_soal'] ?? 'pilihan_ganda')));

            if (!in_array($jenisSoal, ['pilihan_ganda', 'majemuk', 'benar_salah', 'jawaban_singkat', 'tka_kategori'], true)) {
                throw new RuntimeException(
                    "Baris {$excelRow}: Jenis Soal '{$jenisSoal}' tidak didukung."
                );
            }

            $opsi = [];
            $kunciJawaban = null;

            if (in_array($jenisSoal, ['pilihan_ganda', 'majemuk'], true)) {
                $rawOpsi = [
                    'A' => QuestionHtmlSanitizer::clean(trim((string) ($row['opsi_a'] ?? ''))),
                    'B' => QuestionHtmlSanitizer::clean(trim((string) ($row['opsi_b'] ?? ''))),
                    'C' => QuestionHtmlSanitizer::clean(trim((string) ($row['opsi_c'] ?? ''))),
                    'D' => QuestionHtmlSanitizer::clean(trim((string) ($row['opsi_d'] ?? ''))),
                    'E' => QuestionHtmlSanitizer::clean(trim((string) ($row['opsi_e'] ?? ''))),
                ];

                foreach ($rawOpsi as $key => $value) {
                    if ($value !== '') {
                        $opsi[$key] = $value;
                    }
                }

                if ($jenisSoal === 'pilihan_ganda') {
                    $kunciJawaban = strtoupper(trim((string) ($row['kunci_jawaban'] ?? '')));
                } else {
                    $rawKunci = trim((string) ($row['kunci_jawaban'] ?? ''));
                    $kunciArr = array_values(array_filter(
                        array_map(
                            fn ($value) => strtoupper(trim((string) $value)),
                            explode(',', $rawKunci)
                        ),
                        fn ($value) => $value !== ''
                    ));
                    sort($kunciArr);
                    $kunciJawaban = json_encode($kunciArr, JSON_UNESCAPED_UNICODE);
                }
            } elseif ($jenisSoal === 'benar_salah') {
                $opsi = ['B' => 'Benar', 'S' => 'Salah'];
                $kunciJawaban = strtoupper(trim((string) ($row['kunci_jawaban'] ?? '')));
            } else {
                $kunciJawaban = trim((string) ($row['kunci_jawaban'] ?? ''));
            }

            if (($jenisSoal !== 'tka_kategori') && (empty($kunciJawaban) || $kunciJawaban === '[]')) {
                throw new RuntimeException("Baris {$excelRow}: Kunci Jawaban wajib diisi.");
            }

            $pertanyaan = trim((string) ($row['pertanyaan'] ?? ''));

            if ($pertanyaan === '') {
                throw new RuntimeException("Baris {$excelRow}: Pertanyaan wajib diisi.");
            }

            if ($jenisSoal === 'tka_kategori' && !$this->repairImages) {
                $statements = TkaCategory::statements((string)($row['tka_pernyataan_dan_kunci_json'] ?? ''));
                $group = trim((string)($row['tka_kode_grup'] ?? ''));
                if ($group === '') $group = 'tka-' . (string)Str::uuid();
                if (!preg_match('/^[A-Za-z0-9_-]{1,96}$/', $group)) throw new RuntimeException("Baris {$excelRow}: kode grup TKA tidak valid.");
                $stimulus = trim((string)($row['tka_stimulus_bersama'] ?? ''));
                $stimulus = trim($stimulus . "\n" . $pertanyaan);
                $totalWeight = max(0.1, (float)($row['bobot_nilai'] ?? 1));
                foreach ($statements as $i => $item) {
                    DB::table('question_banks')->insert([
                        'teacher_id' => $this->teacherId, 'subject_id' => (int)$subject->id,
                        'tingkat_kelas' => $grade, 'jenis_soal' => 'benar_salah',
                        'bobot_nilai' => round($totalWeight / count($statements), 4),
                        'level_kognitif' => strtoupper(trim((string)($row['level_kognitif'] ?? 'C3'))),
                        'judul_materi' => trim((string)($row['judul_materi'] ?? '')) ?: null,
                        'indikator' => trim((string)($row['indikator'] ?? '')) ?: null,
                        'pertanyaan' => ($i + 1) . '. ' . e($item['text']),
                        'gambar_soal' => $i === 0 ? ($this->imagesByRow[$excelRow] ?? null) : null,
                        'opsi_jawaban' => json_encode(['B' => 'Benar', 'S' => 'Salah'], JSON_UNESCAPED_UNICODE),
                        'kunci_jawaban' => $item['key'],
                        'tka_group_code' => $group, 'tka_stimulus' => $stimulus,
                        'tka_category' => true, 'tka_statement_order' => $i + 1,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                if (isset($this->imagesByRow[$excelRow])) {
                    $this->savedImageCount++;
                    $this->usedImageRows[$excelRow] = true;
                }
                continue;
            }

            if ($this->repairImages) {
                $matches = DB::table('question_banks')
                    ->where('teacher_id', $this->teacherId)
                    ->where('subject_id', (int) $subject->id)
                    ->where('tingkat_kelas', $grade)
                    ->where('pertanyaan', $pertanyaan)
                    ->limit(2)
                    ->pluck('id');
                if ($matches->count() !== 1) {
                    throw new RuntimeException("Baris {$excelRow}: tidak ditemukan tepat satu soal lama yang cocok berdasarkan guru, mapel, kelas, dan pertanyaan. Tidak ada perubahan disimpan.");
                }
                DB::table('question_banks')
                    ->where('id', $matches->first())
                    ->where('teacher_id', $this->teacherId)
                    ->update([
                        'gambar_soal' => $this->imagesByRow[$excelRow],
                        'updated_at' => now(),
                    ]);
                $this->savedImageCount++;
                $this->usedImageRows[$excelRow] = true;
                continue;
            }

            // Jika kolom P berisi teks/URL tetapi tidak ada objek gambar,
            // jangan diam-diam menyimpan soal tanpa gambar yang dimaksud guru.
            $imageCell = trim((string) (
                $row['gambar_soal_tempel_gambar_di_sel_ini']
                ?? $row['gambar_soal']
                ?? ''
            ));
            if ($imageCell !== '' && !isset($this->imagesByRow[$excelRow])) {
                throw new RuntimeException(
                    "Baris {$excelRow}: tempel gambar sebagai objek Excel di kolom P, bukan nama file atau URL."
                );
            }

            DB::table('question_banks')->insert([
                'teacher_id' => $this->teacherId,
                'subject_id' => (int) $subject->id,
                'tingkat_kelas' => $grade,
                'jenis_soal' => $jenisSoal,
                'bobot_nilai' => (float) ($row['bobot_nilai'] ?? 1),
                'level_kognitif' => strtoupper(trim((string) ($row['level_kognitif'] ?? 'C3'))),
                'judul_materi' => trim((string) ($row['judul_materi'] ?? '')) ?: null,
                'indikator' => trim((string) ($row['indikator'] ?? '')) ?: null,
                'pertanyaan' => QuestionHtmlSanitizer::clean($pertanyaan),
                'gambar_soal' => $this->imagesByRow[$excelRow] ?? null,
                'opsi_jawaban' => $opsi !== [] ? json_encode($opsi, JSON_UNESCAPED_UNICODE) : null,
                'kunci_jawaban' => $kunciJawaban,
                'tka_group_code' => trim((string)($row['tka_kode_grup'] ?? '')) ?: null,
                'tka_stimulus' => trim((string)($row['tka_stimulus_bersama'] ?? '')) ?: null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (isset($this->imagesByRow[$excelRow])) {
                $this->savedImageCount++;
                $this->usedImageRows[$excelRow] = true;
            }
        }
        $missingRows = array_diff_key($this->imagesByRow, $this->usedImageRows);
        if ($missingRows !== []) {
            throw new RuntimeException('Gambar pada baris ' . implode(', ', array_keys($missingRows))
                . ' tidak terhubung ke soal. Impor dibatalkan untuk melindungi kelengkapan data.');
        }
    }
}

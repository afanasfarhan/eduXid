<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

class SchoolProfileController extends Controller
{
    public function index(Request $request)
    {
        $data = $this->buildProfileData();

        return view('tu.profil.index', $data);
    }

    public function export(Request $request): BinaryFileResponse
    {
        $data = $this->buildProfileData();

        $template = resource_path('profile-template/profil-sekolah.xlsx');

        if (!is_file($template)) {
            abort(500, 'Template Profil Sekolah tidak ditemukan.');
        }

        try {
            $spreadsheet = IOFactory::load($template);
            $this->fillWorkbook($spreadsheet, $data, $request);
        } catch (Throwable $e) {
            report($e);

            throw new \RuntimeException(
                'Gagal menyiapkan workbook Profil Sekolah: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $schoolName = trim((string) ($data['school']->nama ?? 'Sekolah'));
        $slug = Str::slug($schoolName, '-');
        $slug = $slug !== '' ? $slug : 'sekolah';

        $filename = 'profil-' . $slug . '-' . now()->format('Y-m-d_H-i-s') . '.xlsx';
        $tmp = storage_path('app/' . uniqid('profil-sekolah-', true) . '.xlsx');

        try {
            (new Xlsx($spreadsheet))->save($tmp);
        } catch (Throwable $e) {
            report($e);

            if (isset($spreadsheet)) {
                $spreadsheet->disconnectWorksheets();
            }

            @unlink($tmp);

            throw new \RuntimeException(
                'Gagal menulis file Excel Profil Sekolah: ' . $e->getMessage(),
                0,
                $e
            );
        }

        $spreadsheet->disconnectWorksheets();

        return response()
            ->download($tmp, $filename, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            ])
            ->deleteFileAfterSend(true);
    }

    private function buildProfileData(): array
    {
        $school = Schema::hasTable('schools')
            ? DB::table('schools')->first()
            : null;

        $students = $this->tableRows('students', function ($query) {
            if (Schema::hasColumn('students', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
        });

        $teachers = $this->tableRows('teachers', function ($query) {
            if (Schema::hasColumn('teachers', 'deleted_at')) {
                $query->whereNull('deleted_at');
            }
        });

        $homerooms = $this->regularHomerooms();
        $enrollments = Schema::hasTable('enrollments')
            ? DB::table('enrollments')->get()
            : collect();

        $subjects = Schema::hasTable('subjects')
            ? DB::table('subjects')->get()->keyBy('id')
            : collect();

        $assignments = Schema::hasTable('teaching_assignments')
            ? DB::table('teaching_assignments')->get()
            : collect();

        $studentById = $students->keyBy('id');
        $homeroomById = $homerooms->keyBy('id');

        $classByStudent = [];
        foreach ($enrollments as $enrollment) {
            $homeroomId = (int) ($enrollment->homeroom_id ?? 0);
            $studentId = (int) ($enrollment->student_id ?? 0);

            if ($studentId <= 0 || !$homeroomById->has($homeroomId)) {
                continue;
            }

            $classByStudent[$studentId] = $homeroomById->get($homeroomId);
        }

        $gender = ['L' => 0, 'P' => 0];

        foreach ($students as $student) {
            $jk = $this->gender($student);
            if (isset($gender[$jk])) {
                $gender[$jk]++;
            }
        }

        $ageStats = [
            '< 6 tahun' => ['L' => 0, 'P' => 0],
            '6 - 12 tahun' => ['L' => 0, 'P' => 0],
            '13 - 15 tahun' => ['L' => 0, 'P' => 0],
            '16 - 20 tahun' => ['L' => 0, 'P' => 0],
            '> 20 tahun' => ['L' => 0, 'P' => 0],
        ];

        $religions = [
            'Islam' => ['L' => 0, 'P' => 0],
            'Kristen' => ['L' => 0, 'P' => 0],
            'Katholik' => ['L' => 0, 'P' => 0],
            'Hindu' => ['L' => 0, 'P' => 0],
            'Budha' => ['L' => 0, 'P' => 0],
            'Konghucu' => ['L' => 0, 'P' => 0],
            'Kepercayaan YME' => ['L' => 0, 'P' => 0],
            'Lainnya' => ['L' => 0, 'P' => 0],
        ];

        $incomeStats = [
            'Tidak di isi' => ['L' => 0, 'P' => 0],
            'Kurang dari Rp. 500,000' => ['L' => 0, 'P' => 0],
            'Rp. 500,000 - Rp. 999,999' => ['L' => 0, 'P' => 0],
            'Rp. 1,000,000 - Rp. 1,999,999' => ['L' => 0, 'P' => 0],
            'Rp. 2,000,000 - Rp. 4,999,999' => ['L' => 0, 'P' => 0],
            'Rp. 5,000,000 - Rp. 20,000,000' => ['L' => 0, 'P' => 0],
            'Lebih dari Rp. 20,000,000' => ['L' => 0, 'P' => 0],
        ];

        $levelStats = [
            10 => ['L' => 0, 'P' => 0],
            11 => ['L' => 0, 'P' => 0],
            12 => ['L' => 0, 'P' => 0],
        ];

        foreach ($students as $student) {
            $jk = $this->gender($student);
            if (!isset($gender[$jk])) {
                continue;
            }

            $dob = $this->pick($student, ['tanggal_lahir', 'tgl_lahir']);
            $age = null;

            if ($dob) {
                try {
                    $age = Carbon::parse((string) $dob)->age;
                } catch (Throwable) {
                    $age = null;
                }
            }

            $ageKey = match (true) {
                $age === null => null,
                $age < 6 => '< 6 tahun',
                $age <= 12 => '6 - 12 tahun',
                $age <= 15 => '13 - 15 tahun',
                $age <= 20 => '16 - 20 tahun',
                default => '> 20 tahun',
            };

            if ($ageKey !== null) {
                $ageStats[$ageKey][$jk]++;
            }

            $religion = $this->normalizeReligion(
                $this->pick($student, [
                    'agama',
                    'agama_id_str',
                    'agama_nama',
                ])
            );
            $religions[$religion][$jk]++;

            $income = $this->normalizeIncome(
                $this->pick($student, [
                    'penghasilan_orang_tua',
                    'penghasilan_ortu',
                    'penghasilan_ayah_id_str',
                    'penghasilan_ayah',
                    'penghasilan_wali_id_str',
                ])
            );
            $incomeStats[$income][$jk]++;

            $class = $classByStudent[(int) ($student->id ?? 0)] ?? null;
            if ($class) {
                $level = $this->gradeLevel($class);
                if (isset($levelStats[$level])) {
                    $levelStats[$level][$jk]++;
                }
            }
        }

        $teacherStats = [
            'total' => $teachers->count(),
            'guru' => 0,
            'tendik' => 0,
            'laki_laki' => 0,
            'perempuan' => 0,
        ];

        foreach ($teachers as $teacher) {
            $jk = $this->gender($teacher);
            if ($jk === 'L') {
                $teacherStats['laki_laki']++;
            } elseif ($jk === 'P') {
                $teacherStats['perempuan']++;
            }

            $jenis = strtolower((string) $this->pick($teacher, [
                'jenis_ptk',
                'jenis_ptk_id_str',
                'jabatan_ptk',
            ], ''));

            if (str_contains($jenis, 'tenaga') || str_contains($jenis, 'administrasi')) {
                $teacherStats['tendik']++;
            } else {
                $teacherStats['guru']++;
            }
        }

        $rombelRows = $this->buildHomeroomRows($homerooms, $enrollments, $studentById, $teachers);
        $ptkRows = $this->buildPtkRows($teachers, $assignments, $subjects, $enrollments, $homeroomById);

        $prasarana = $this->optionalTableRows([
            'prasaranas',
            'prasarana',
            'school_infrastructures',
        ]);

        $sarana = $this->optionalTableRows([
            'saranas',
            'sarana',
            'school_facilities',
        ]);

        $blockgrant = $this->optionalTableRows([
            'blockgrants',
            'blockgrant',
            'school_blockgrants',
        ]);

        return [
            'school' => $school,
            'students' => $students,
            'teachers' => $teachers,
            'homerooms' => $homerooms,
            'gender' => $gender,
            'ageStats' => $ageStats,
            'religions' => $religions,
            'incomeStats' => $incomeStats,
            'levelStats' => $levelStats,
            'teacherStats' => $teacherStats,
            'rombelRows' => $rombelRows,
            'ptkRows' => $ptkRows,
            'prasarana' => $prasarana,
            'sarana' => $sarana,
            'blockgrant' => $blockgrant,
            'dataAvailability' => [
                'Profil Sekolah' => $school !== null,
                'PTK' => $teachers->isNotEmpty(),
                'Peserta Didik' => $students->isNotEmpty(),
                'Rombongan Belajar' => $homerooms->isNotEmpty(),
                'Prasarana' => $prasarana['available'],
                'Sarana' => $sarana['available'],
                'Blockgrant' => $blockgrant['available'],
            ],
        ];
    }

    private function fillWorkbook(Spreadsheet $spreadsheet, array $data, Request $request): void
    {
        $school = $data['school'];
        $schoolName = trim((string) ($school->nama ?? 'Sekolah'));

        $profile = $spreadsheet->getSheet(0);
        $profileTitle = $this->sheetTitle('Profil ' . $schoolName);
        $profile->setTitle($profileTitle);

        $this->fillProfileSheet($profile, $school);

        $commonSheets = [
            1 => 'PTK',
            2 => 'Peserta Didik',
            3 => 'Rombongan Belajar',
            4 => 'Prasarana',
            5 => 'Sarana',
            6 => 'Blockgrant',
        ];

        foreach ($commonSheets as $index => $name) {
            $sheet = $spreadsheet->getSheet($index);
            $this->fillCommonHeader($sheet, $school, $request);
        }

        $this->fillPtkSheet($spreadsheet->getSheet(1), $data['ptkRows']);
        $this->fillStudentSheet(
            $spreadsheet->getSheet(2),
            $data['gender'],
            $data['ageStats'],
            $data['religions'],
            $data['incomeStats'],
            $data['levelStats']
        );
        $this->fillHomeroomSheet($spreadsheet->getSheet(3), $data['rombelRows']);
        $this->fillPrasaranaSheet($spreadsheet->getSheet(4), $data['prasarana']);
        $this->fillSaranaSheet($spreadsheet->getSheet(5), $data['sarana']);
        $this->fillBlockgrantSheet($spreadsheet->getSheet(6), $data['blockgrant']);
    }

    private function fillProfileSheet(Worksheet $sheet, ?object $school): void
    {
        // Bersihkan seluruh nilai contoh yang merupakan data sekolah,
        // tetapi pertahankan label/static text dari template.
        foreach (range(4, 60) as $row) {
            if ((string) $sheet->getCell("C{$row}")->getValue() === ':'
                || (string) $sheet->getCell("C{$row}")->getValue() === ': ') {
                $sheet->setCellValue("D{$row}", null);
            }
        }

        $sheet->setCellValue('D9', null);
        $sheet->setCellValue('F9', null);
        $sheet->setCellValue('D16', null);
        $sheet->setCellValue('D17', null);

        foreach (range(62, 70) as $row) {
            $sheet->setCellValue("D{$row}", null);
        }
        $sheet->setCellValue('D72', null);
        $sheet->setCellValue('D73', null);
        foreach (['D75', 'F75', 'H75', 'D77', 'F77', 'H77'] as $cell) {
            $sheet->setCellValue($cell, null);
        }
        $this->clearRange($sheet, 'D81:I85');

        if (!$school) {
            return;
        }

        $map = [
            'Nama Sekolah' => ['nama'],
            'NPSN' => ['npsn'],
            'Jenjang Pendidikan' => ['bentuk_pendidikan', 'jenjang_pendidikan'],
            'Status Sekolah' => ['status_sekolah'],
            'Alamat Sekolah' => ['alamat_jalan', 'alamat'],
            'Kode Pos' => ['kode_pos'],
            'Kelurahan' => ['desa_kelurahan', 'kelurahan'],
            'Kecamatan' => ['kecamatan'],
            'Kabupaten/Kota' => ['kabupaten_kota', 'kabupaten'],
            'Provinsi' => ['provinsi'],
            'Negara' => ['negara'],
            'SK Pendirian Sekolah' => ['sk_pendirian_sekolah', 'sk_pendirian'],
            'Tanggal SK Pendirian' => ['tanggal_sk_pendirian', 'tgl_sk_pendirian'],
            'Status Kepemilikan' => ['status_kepemilikan'],
            'SK Izin Operasional' => ['sk_izin_operasional'],
            'Tgl SK Izin Operasional' => ['tanggal_sk_izin_operasional', 'tgl_sk_izin_operasional'],
            'Kebutuhan Khusus Dilayani' => ['kebutuhan_khusus_dilayani'],
            'Nomor Rekening' => ['nomor_rekening', 'no_rekening'],
            'Nama Bank' => ['nama_bank', 'bank'],
            'Cabang KCP/Unit' => ['cabang_kcp_unit', 'cabang_bank'],
            'Rekening Atas Nama' => ['rekening_atas_nama'],
            'MBS' => ['mbs'],
            'Iuran Tahunan' => ['iuran_tahunan'],
            'Iuran Bulanan' => ['iuran_bulanan'],
            'Nama Wajib Pajak' => ['nama_wajib_pajak'],
            'NPWP' => ['npwp'],
            'Nomor Telepon' => ['nomor_telepon', 'telepon'],
            'Nomor Fax' => ['nomor_fax', 'fax'],
            'Email' => ['email'],
            'Website' => ['website'],
            'Waktu Penyelenggaraan' => ['waktu_penyelenggaraan'],
            'Bersedia Menerima Bos?' => ['bersedia_menerima_bos', 'menerima_bos'],
            'Sertifikasi ISO' => ['sertifikasi_iso'],
            'Sumber Listrik' => ['sumber_listrik'],
            'Total Daya Listrik (watt)' => ['daya_listrik', 'total_daya_listrik'],
            'Akses Internet' => ['akses_internet'],
            'Sumber air' => ['sumber_air'],
            'Sumber air minum' => ['sumber_air_minum'],
            'Kecukupan air bersih' => ['kecukupan_air_bersih'],
            'Tipe jamban' => ['tipe_jamban'],
            'Jumlah tempat cuci tangan' => ['jumlah_tempat_cuci_tangan'],
            'Jumlah tempat cuci tangan rusak' => ['jumlah_tempat_cuci_tangan_rusak'],
        ];

        for ($row = 1; $row <= $sheet->getHighestRow(); $row++) {
            $label = trim((string) $sheet->getCell("B{$row}")->getValue());

            if ($label === '' || !isset($map[$label])) {
                continue;
            }

            $value = $this->pick($school, $map[$label]);

            if ($label === 'Negara' && ($value === null || $value === '')) {
                $value = 'Indonesia';
            }

            $this->writeCell($sheet, "D{$row}", $value);
        }

        $this->writeCell($sheet, 'D9', $this->pick($school, ['rt']));
        $this->writeCell($sheet, 'F9', $this->pick($school, ['rw']));
        $this->writeCell($sheet, 'D16', $this->pick($school, ['lintang', 'latitude']));
        $this->writeCell($sheet, 'D17', $this->pick($school, ['bujur', 'longitude']));
    }

    private function fillCommonHeader(Worksheet $sheet, ?object $school, Request $request): void
    {
        $name = trim((string) ($school->nama ?? 'Sekolah'));
        $kecamatan = trim((string) ($school->kecamatan ?? '-'));
        $kabupaten = trim((string) ($school->kabupaten_kota ?? $school->kabupaten ?? '-'));
        $provinsi = trim((string) ($school->provinsi ?? '-'));

        $sheet->setCellValue('A2', $name);
        $sheet->setCellValue(
            'A3',
            "Kecamatan {$kecamatan}, Kabupaten {$kabupaten}, Provinsi {$provinsi}"
        );
        $sheet->setCellValue('A4', 'Tanggal Unduh: ' . now()->format('Y-m-d H:i:s'));

        $user = $request->user();
        $identity = trim((string) ($user->name ?? 'Pengguna'));
        $email = trim((string) ($user->email ?? ''));

        if ($email !== '') {
            $identity .= " ({$email})";
        }

        $sheet->setCellValue('D4', 'Pengunduh: ' . $identity);
    }

    private function fillPtkSheet(Worksheet $sheet, Collection $rows): void
    {
        $start = 7;
        $existingLast = max($start, $sheet->getHighestRow());
        $this->clearRange($sheet, "A{$start}:X{$existingLast}");

        $count = $rows->count();
        if ($count <= 0) {
            return;
        }

        $end = $start + $count - 1;
        $sheet->duplicateStyle($sheet->getStyle("A{$start}:X{$start}"), "A{$start}:X{$end}");

        foreach ($rows->values() as $i => $row) {
            $r = $start + $i;
            $values = [
                $i + 1,
                $row['nama'],
                $row['nuptk'],
                $row['jk'],
                $row['tempat_lahir'],
                $row['tanggal_lahir'],
                $row['nip'],
                $row['status_kepegawaian'],
                $row['jenis_ptk'],
                $row['keterangan'],
                $row['gelar_depan'],
                $row['gelar_belakang'],
                $row['jenjang'],
                $row['jurusan'],
                $row['sertifikasi'],
                $row['tmt_kerja'],
                $row['tugas_tambahan'],
                $row['mengajar'],
                $row['jam_tugas_tambahan'],
                $row['jjm'],
                $row['total_jjm'],
                $row['siswa'],
                $row['kompetensi'],
                $row['nik'],
                $row['jabatan_ptk'],
            ];

            foreach ($values as $col => $value) {
                $coordinate = Coordinate::stringFromColumnIndex($col + 1) . $r;
                $cell = $sheet->getCell($coordinate);
                if (in_array($col + 1, [3, 7, 24], true)) {
                    $cell->setValueExplicit((string) ($value ?? ''), DataType::TYPE_STRING);
                } else {
                    $cell->setValue($value);
                }
            }
        }
    }

    private function fillStudentSheet(
        Worksheet $sheet,
        array $gender,
        array $ageStats,
        array $religions,
        array $incomeStats,
        array $levelStats
    ): void {
        $totalL = (int) ($gender['L'] ?? 0);
        $totalP = (int) ($gender['P'] ?? 0);
        $total = $totalL + $totalP;

        $sheet->fromArray([[$totalL, $totalP, $total]], null, 'A8');

        $ageRows = [
            '< 6 tahun' => 12,
            '6 - 12 tahun' => 13,
            '13 - 15 tahun' => 14,
            '16 - 20 tahun' => 15,
            '> 20 tahun' => 16,
        ];

        foreach ($ageRows as $key => $row) {
            $l = (int) ($ageStats[$key]['L'] ?? 0);
            $p = (int) ($ageStats[$key]['P'] ?? 0);
            $sheet->fromArray([[$l, $p, $l + $p]], null, "B{$row}");
        }
        $sheet->fromArray([[$totalL, $totalP, $total]], null, 'B17');

        $religionRows = [
            'Islam' => 21,
            'Kristen' => 22,
            'Katholik' => 23,
            'Hindu' => 24,
            'Budha' => 25,
            'Konghucu' => 26,
            'Kepercayaan YME' => 27,
            'Lainnya' => 28,
        ];

        foreach ($religionRows as $key => $row) {
            $l = (int) ($religions[$key]['L'] ?? 0);
            $p = (int) ($religions[$key]['P'] ?? 0);
            $sheet->fromArray([[$l, $p, $l + $p]], null, "B{$row}");
        }
        $sheet->fromArray([[$totalL, $totalP, $total]], null, 'B29');

        $incomeRows = [
            'Tidak di isi' => 8,
            'Kurang dari Rp. 500,000' => 9,
            'Rp. 500,000 - Rp. 999,999' => 10,
            'Rp. 1,000,000 - Rp. 1,999,999' => 11,
            'Rp. 2,000,000 - Rp. 4,999,999' => 12,
            'Rp. 5,000,000 - Rp. 20,000,000' => 13,
            'Lebih dari Rp. 20,000,000' => 14,
        ];

        foreach ($incomeRows as $key => $row) {
            $l = (int) ($incomeStats[$key]['L'] ?? 0);
            $p = (int) ($incomeStats[$key]['P'] ?? 0);
            $sheet->fromArray([[$l, $p, $l + $p]], null, "H{$row}");
        }
        $sheet->fromArray([[$totalL, $totalP, $total]], null, 'H15');

        foreach ([11 => 8, 12 => 9, 10 => 10] as $level => $row) {
            $l = (int) ($levelStats[$level]['L'] ?? 0);
            $p = (int) ($levelStats[$level]['P'] ?? 0);
            $sheet->fromArray([[$l, $p, $l + $p]], null, "M{$row}");
        }
        $sheet->fromArray([[$totalL, $totalP, $total]], null, 'M11');
    }

    private function fillHomeroomSheet(Worksheet $sheet, Collection $rows): void
    {
        $start = 8;
        $existingLast = max($start, $sheet->getHighestRow());
        $this->clearRange($sheet, "A{$start}:I{$existingLast}");

        if ($rows->isEmpty()) {
            return;
        }

        $end = $start + $rows->count() - 1;
        $sheet->duplicateStyle($sheet->getStyle("A{$start}:I{$start}"), "A{$start}:I{$end}");

        foreach ($rows->values() as $i => $row) {
            $r = $start + $i;
            $sheet->fromArray([[
                $i + 1,
                $row['nama'],
                $row['tingkat'],
                $row['l'],
                $row['p'],
                $row['total'],
                $row['wali_kelas'],
                $row['kurikulum'],
                $row['ruangan'],
            ]], null, "A{$r}");
        }
    }

    private function fillPrasaranaSheet(Worksheet $sheet, array $source): void
    {
        $start = 8;
        $this->clearRange($sheet, "A{$start}:F" . max($start, $sheet->getHighestRow()));

        $rows = $source['rows'];
        if ($rows->isEmpty()) {
            return;
        }

        $end = $start + $rows->count() - 1;
        $sheet->duplicateStyle($sheet->getStyle("A{$start}:F{$start}"), "A{$start}:F{$end}");

        foreach ($rows->values() as $i => $row) {
            $sheet->fromArray([[
                $i + 1,
                $this->pick($row, ['nama_prasarana', 'nama', 'jenis_prasarana']),
                $this->pick($row, ['keterangan', 'deskripsi']),
                $this->pick($row, ['panjang']),
                $this->pick($row, ['lebar']),
                $this->pick($row, ['status_kepemilikan', 'kepemilikan']),
            ]], null, 'A' . ($start + $i));
        }
    }

    private function fillSaranaSheet(Worksheet $sheet, array $source): void
    {
        $start = 7;
        $this->clearRange($sheet, "A{$start}:H" . max($start, $sheet->getHighestRow()));

        $rows = $source['rows'];
        if ($rows->isEmpty()) {
            return;
        }

        $end = $start + $rows->count() - 1;
        $sheet->duplicateStyle($sheet->getStyle("A{$start}:H{$start}"), "A{$start}:H{$end}");

        foreach ($rows->values() as $i => $row) {
            $sheet->fromArray([[
                $i + 1,
                $this->pick($row, ['jenis_sarana', 'nama_sarana', 'nama']),
                $this->pick($row, ['letak', 'nama_prasarana', 'ruangan']),
                $this->pick($row, ['kepemilikan', 'status_kepemilikan']),
                $this->pick($row, ['spesifikasi', 'keterangan']),
                $this->pick($row, ['jumlah'], 0),
                $this->pick($row, ['laik', 'jumlah_laik'], 0),
                $this->pick($row, ['tidak_laik', 'jumlah_tidak_laik'], 0),
            ]], null, 'A' . ($start + $i));
        }
    }

    private function fillBlockgrantSheet(Worksheet $sheet, array $source): void
    {
        $start = 7;
        $this->clearRange($sheet, "A{$start}:G" . max($start, $sheet->getHighestRow()));

        $rows = $source['rows'];
        if ($rows->isEmpty()) {
            return;
        }

        $end = $start + $rows->count() - 1;
        $sheet->duplicateStyle($sheet->getStyle("A{$start}:G{$start}"), "A{$start}:G{$end}");

        foreach ($rows->values() as $i => $row) {
            $sheet->fromArray([[
                $i + 1,
                $this->pick($row, ['tahun']),
                $this->pick($row, ['jenis_bantuan', 'jenis']),
                $this->pick($row, ['sumber_bantuan', 'sumber']),
                $this->pick($row, ['besar_bantuan', 'nominal'], 0),
                $this->pick($row, ['dana_pendamping'], 0),
                $this->pick($row, ['peruntukan_dana', 'peruntukan']),
            ]], null, 'A' . ($start + $i));
        }
    }

    private function buildHomeroomRows(
        Collection $homerooms,
        Collection $enrollments,
        Collection $studentById,
        Collection $teachers
    ): Collection {
        $teacherById = $teachers->keyBy('id');
        $enrollmentsByHomeroom = $enrollments->groupBy('homeroom_id');

        return $homerooms->map(function ($homeroom) use ($teacherById, $enrollmentsByHomeroom, $studentById) {
            $l = 0;
            $p = 0;

            foreach ($enrollmentsByHomeroom->get($homeroom->id, collect()) as $enrollment) {
                $student = $studentById->get($enrollment->student_id ?? null);
                if (!$student) {
                    continue;
                }

                $jk = $this->gender($student);
                if ($jk === 'L') {
                    $l++;
                } elseif ($jk === 'P') {
                    $p++;
                }
            }

            $teacher = $teacherById->get($homeroom->homeroom_teacher_id ?? null);

            return [
                'nama' => $this->pick($homeroom, ['nama', 'nama_rombel'], '-'),
                'tingkat' => $this->gradeLevel($homeroom) ?: '',
                'l' => $l,
                'p' => $p,
                'total' => $l + $p,
                'wali_kelas' => $teacher
                    ? $this->pick($teacher, ['nama_lengkap', 'nama'], '-')
                    : '-',
                'kurikulum' => $this->pick($homeroom, ['kurikulum', 'kurikulum_id_str'], '-'),
                'ruangan' => $this->pick($homeroom, ['ruangan', 'nama_ruang', 'ruang'], '-'),
            ];
        })->values();
    }

    private function buildPtkRows(
        Collection $teachers,
        Collection $assignments,
        Collection $subjects,
        Collection $enrollments,
        Collection $homeroomById
    ): Collection {
        $assignmentByTeacher = $assignments->groupBy('teacher_id');
        $enrollmentsByHomeroom = $enrollments->groupBy('homeroom_id');

        return $teachers->map(function ($teacher) use (
            $assignmentByTeacher,
            $subjects,
            $enrollmentsByHomeroom,
            $homeroomById
        ) {
            $teacherAssignments = $assignmentByTeacher->get($teacher->id, collect());

            $subjectNames = [];
            $jjm = 0;
            $studentIds = [];

            foreach ($teacherAssignments as $assignment) {
                $subject = $subjects->get($assignment->subject_id ?? null);
                if ($subject) {
                    $name = $this->pick($subject, ['nama_mata_pelajaran', 'nama'], '');
                    if ($name !== '') {
                        $subjectNames[] = $name;
                    }
                }

                $jjm += (int) $this->pick($assignment, [
                    'jam_mengajar_per_minggu',
                    'jumlah_jam',
                    'jjm',
                    'hours_per_week',
                ], 0);

                $homeroomId = $assignment->homeroom_id ?? null;
                if ($homeroomById->has($homeroomId)) {
                    foreach ($enrollmentsByHomeroom->get($homeroomId, collect()) as $enrollment) {
                        if (!empty($enrollment->student_id)) {
                            $studentIds[(int) $enrollment->student_id] = true;
                        }
                    }
                }
            }

            $subjectNames = array_values(array_unique(array_filter($subjectNames)));
            sort($subjectNames);

            $jamTambahan = (int) $this->pick($teacher, [
                'jam_tugas_tambahan',
                'jjm_tugas_tambahan',
            ], 0);

            return [
                'nama' => $this->pick($teacher, ['nama_lengkap', 'nama'], '-'),
                'nuptk' => $this->pick($teacher, ['nuptk'], ''),
                'jk' => $this->gender($teacher),
                'tempat_lahir' => $this->pick($teacher, ['tempat_lahir'], ''),
                'tanggal_lahir' => $this->pick($teacher, ['tanggal_lahir'], ''),
                'nip' => $this->pick($teacher, ['nip'], ''),
                'status_kepegawaian' => $this->pick($teacher, [
                    'status_kepegawaian',
                    'status_kepegawaian_id_str',
                ], ''),
                'jenis_ptk' => $this->pick($teacher, [
                    'jenis_ptk',
                    'jenis_ptk_id_str',
                ], ''),
                'keterangan' => $this->pick($teacher, ['keterangan'], ''),
                'gelar_depan' => $this->pick($teacher, ['gelar_depan'], ''),
                'gelar_belakang' => $this->pick($teacher, ['gelar_belakang'], ''),
                'jenjang' => $this->pick($teacher, [
                    'pendidikan_terakhir',
                    'jenjang_pendidikan',
                ], ''),
                'jurusan' => $this->pick($teacher, [
                    'bidang_studi',
                    'jurusan',
                    'jurusan_prodi',
                ], ''),
                'sertifikasi' => $this->pick($teacher, [
                    'sertifikasi',
                    'bidang_sertifikasi',
                ], ''),
                'tmt_kerja' => $this->pick($teacher, [
                    'tmt_kerja',
                    'tmt_pengangkatan',
                ], ''),
                'tugas_tambahan' => $this->pick($teacher, [
                    'tugas_tambahan',
                    'jabatan_tambahan',
                ], ''),
                'mengajar' => implode(', ', $subjectNames),
                'jam_tugas_tambahan' => $jamTambahan ?: null,
                'jjm' => $jjm ?: null,
                'total_jjm' => ($jjm + $jamTambahan) ?: null,
                'siswa' => count($studentIds) > 0 ? count($studentIds) . ' siswa' : null,
                'kompetensi' => $this->pick($teacher, [
                    'kompetensi',
                    'bidang_studi',
                ], ''),
                'nik' => $this->pick($teacher, ['nik'], ''),
                'jabatan_ptk' => $this->pick($teacher, [
                    'jabatan_ptk',
                    'jabatan',
                ], ''),
            ];
        })->values();
    }

    private function regularHomerooms(): Collection
    {
        if (!Schema::hasTable('homerooms')) {
            return collect();
        }

        $query = DB::table('homerooms');

        if (Schema::hasColumn('homerooms', 'semester_id')) {
            $semesterId = DB::table('homerooms')->max('semester_id');
            if ($semesterId !== null && $semesterId !== '') {
                $query->where('semester_id', $semesterId);
            }
        }

        if (Schema::hasColumn('homerooms', 'jenis_rombel')) {
            $query
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%');
        }

        return $query->orderBy(
            Schema::hasColumn('homerooms', 'nama') ? 'nama' : 'id'
        )->get();
    }

    private function optionalTableRows(array $candidates): array
    {
        foreach ($candidates as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }

            return [
                'available' => true,
                'table' => $table,
                'rows' => DB::table($table)->get(),
            ];
        }

        return [
            'available' => false,
            'table' => null,
            'rows' => collect(),
        ];
    }

    private function tableRows(string $table, ?callable $callback = null): Collection
    {
        if (!Schema::hasTable($table)) {
            return collect();
        }

        $query = DB::table($table);

        if ($callback) {
            $callback($query);
        }

        return $query->get();
    }

    private function gradeLevel(object $homeroom): ?int
    {
        $raw = $this->pick($homeroom, [
            'tingkat_pendidikan',
            'tingkat_kelas',
            'grade_level',
        ]);

        if (is_numeric($raw)) {
            $level = (int) $raw;
            if ($level >= 1 && $level <= 13) {
                return $level;
            }
        }

        $name = strtoupper((string) $this->pick($homeroom, ['nama', 'nama_rombel'], ''));

        if (preg_match('/\bXII\b/', $name)) {
            return 12;
        }
        if (preg_match('/\bXI\b/', $name)) {
            return 11;
        }
        if (preg_match('/\bX\b/', $name)) {
            return 10;
        }

        if (preg_match('/\b(10|11|12)\b/', $name, $m)) {
            return (int) $m[1];
        }

        return null;
    }

    private function gender(object $row): string
    {
        $value = strtoupper(trim((string) $this->pick($row, [
            'jenis_kelamin',
            'jk',
            'gender',
        ], '')));

        if (in_array($value, ['L', 'LAKI-LAKI', 'LAKI LAKI', 'MALE'], true)) {
            return 'L';
        }

        if (in_array($value, ['P', 'PEREMPUAN', 'FEMALE'], true)) {
            return 'P';
        }

        return '';
    }

    private function normalizeReligion(mixed $value): string
    {
        $v = strtolower(trim((string) $value));

        return match (true) {
            str_contains($v, 'islam') => 'Islam',
            str_contains($v, 'kristen') || str_contains($v, 'protestan') => 'Kristen',
            str_contains($v, 'katolik') || str_contains($v, 'katholik') => 'Katholik',
            str_contains($v, 'hindu') => 'Hindu',
            str_contains($v, 'budha') || str_contains($v, 'buddha') => 'Budha',
            str_contains($v, 'konghucu') => 'Konghucu',
            str_contains($v, 'kepercayaan') => 'Kepercayaan YME',
            default => 'Lainnya',
        };
    }

    private function normalizeIncome(mixed $value): string
    {
        $v = trim((string) $value);

        if ($v === '' || $v === '-' || strtolower($v) === 'tidak di isi') {
            return 'Tidak di isi';
        }

        $lower = strtolower($v);

        foreach ([
            'Kurang dari Rp. 500,000',
            'Rp. 500,000 - Rp. 999,999',
            'Rp. 1,000,000 - Rp. 1,999,999',
            'Rp. 2,000,000 - Rp. 4,999,999',
            'Rp. 5,000,000 - Rp. 20,000,000',
            'Lebih dari Rp. 20,000,000',
        ] as $label) {
            if (strtolower($label) === $lower) {
                return $label;
            }
        }

        $numeric = preg_replace('/[^\d]/', '', $v);

        if ($numeric !== '' && is_numeric($numeric)) {
            $n = (int) $numeric;

            return match (true) {
                $n < 500000 => 'Kurang dari Rp. 500,000',
                $n < 1000000 => 'Rp. 500,000 - Rp. 999,999',
                $n < 2000000 => 'Rp. 1,000,000 - Rp. 1,999,999',
                $n < 5000000 => 'Rp. 2,000,000 - Rp. 4,999,999',
                $n <= 20000000 => 'Rp. 5,000,000 - Rp. 20,000,000',
                default => 'Lebih dari Rp. 20,000,000',
            };
        }

        return 'Tidak di isi';
    }

    private function pick(?object $row, array $keys, mixed $default = null): mixed
    {
        if (!$row) {
            return $default;
        }

        foreach ($keys as $key) {
            if (!property_exists($row, $key)) {
                continue;
            }

            $value = $row->{$key};

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return $default;
    }

    private function writeCell(Worksheet $sheet, string $cell, mixed $value): void
    {
        if ($value === null) {
            $sheet->setCellValue($cell, null);
            return;
        }

        if (is_string($value)) {
            $sheet->getCell($cell)->setValueExplicit($value, DataType::TYPE_STRING);
            return;
        }

        $sheet->setCellValue($cell, $value);
    }

    private function clearRange(Worksheet $sheet, string $range): void
    {
        [$start, $end] = array_pad(explode(':', $range, 2), 2, null);
        $end = $end ?: $start;

        [$startCol, $startRow] = Coordinate::coordinateFromString($start);
        [$endCol, $endRow] = Coordinate::coordinateFromString($end);

        $startIndex = Coordinate::columnIndexFromString($startCol);
        $endIndex = Coordinate::columnIndexFromString($endCol);

        for ($row = (int) $startRow; $row <= (int) $endRow; $row++) {
            for ($col = $startIndex; $col <= $endIndex; $col++) {
                $cell = Coordinate::stringFromColumnIndex($col) . $row;
                $sheet->setCellValue($cell, null);
            }
        }
    }

    private function sheetTitle(string $title): string
    {
        $title = preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', $title) ?? 'Profil Sekolah';
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title);

        if (function_exists('mb_substr')) {
            return mb_substr($title, 0, 31);
        }

        return substr($title, 0, 31);
    }
}

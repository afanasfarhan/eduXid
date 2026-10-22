<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterSheet;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

use Carbon\Carbon;

class StudentController extends Controller
{
    /**
     * ============================================================
     * NORMALISASI TEKS
     * ============================================================
     */
    private function normalizeText($text)
    {
        if (empty($text)) {
            return $text;
        }

        $text = mb_convert_case(
            trim($text),
            MB_CASE_TITLE,
            'UTF-8'
        );

        $abbreviations = [
            'Pns',
            'Pppk',
            'Gtt',
            'Gty',
            'Sd',
            'Smp',
            'Sma',
            'Smk',
            'Bk',
            'Mts',
            'Mi',
            'Ma',
            'Slb',
            'Sltp',
            'Slta'
        ];

        foreach ($abbreviations as $abbr) {
            $text = preg_replace(
                '/\b' . preg_quote($abbr, '/') . '\b/',
                strtoupper($abbr),
                $text
            );
        }

        return $text;
    }


    /**
     * ============================================================
     * NAMA LENGKAP GTK
     * ============================================================
     */
    private function generateNamaLengkap(
        $gelarDepan,
        $nama,
        $gelarBelakang
    ) {
        $gelarDepan = trim((string) $gelarDepan);
        $nama = trim((string) $nama);
        $gelarBelakang = trim((string) $gelarBelakang);

        $hasil = '';

        if ($gelarDepan !== '') {
            $hasil .= $gelarDepan . ' ';
        }

        $hasil .= $nama;

        if ($gelarBelakang !== '') {
            $hasil .= ', ' . $gelarBelakang;
        }

        return trim($hasil);
    }


    /**
     * ============================================================
     * DATA SISWA
     * ============================================================
     */
    public function index(Request $request): View
    {
        $sort = $request->get(
            'sort',
            'nama'
        );

        $direction = $request->get(
            'direction',
            'asc'
        );

        $search = $request->get(
            'search',
            ''
        );

        $allowedSort = [
            'nipd' =>
                'students.nipd',

            'nisn' =>
                'students.nisn',

            'nama' =>
                'students.nama',

            'jenis_kelamin' =>
                'students.jenis_kelamin',

            'kelas' =>
                'homerooms.nama'
        ];

        if (!array_key_exists(
            $sort,
            $allowedSort
        )) {
            $sort = 'nama';
        }

        $sortCol =
            $allowedSort[$sort];

        if (!in_array(
            $direction,
            ['asc', 'desc']
        )) {
            $direction = 'asc';
        }


        /*
         * ========================================================
         * DATA SISWA
         * ========================================================
         */
        $students = DB::table('students')
            ->join(
                'enrollments',
                'students.id',
                '=',
                'enrollments.student_id'
            )
            ->join(
                'homerooms',
                function ($join) {

                    $join->on(
                        'enrollments.homeroom_id',
                        '=',
                        'homerooms.id'
                    )
                    ->where(
                        'homerooms.jenis_rombel',
                        'not like',
                        '%Ekstrakurikuler%'
                    )
                    ->where(
                        'homerooms.jenis_rombel',
                        'not like',
                        '%Pilihan%'
                    );
                }
            )
            ->select(
                'students.*',
                'homerooms.nama as kelas',
                'enrollments.homeroom_id as enroll_homeroom_id'
            )
            ->when(
                $search,
                function ($query, $search) {
                    app(\App\Services\UniversalColumnSearchService::class)
                        ->apply(
                            $query,
                            $search,
                            [
                                'students',
                                'homerooms',
                                'enrollments',
                            ]
                        );
                }
            )
            ->orderBy(
                $sortCol,
                $direction
            )
            ->paginate(15)
            ->withQueryString();


        /*
         * ========================================================
         * NORMALISASI DATA SISWA
         * ========================================================
         */
        $students->getCollection()->transform(
            function ($s) {

                $s->nama =
                    $this->normalizeText(
                        $s->nama
                    );

                $s->tempat_lahir =
                    $this->normalizeText(
                        $s->tempat_lahir ?? null
                    );

                $s->agama =
                    $this->normalizeText(
                        $s->agama ?? null
                    );

                $s->alamat_jalan =
                    $this->normalizeText(
                        $s->alamat_jalan ?? null
                    );

                $s->nama_ayah =
                    $this->normalizeText(
                        $s->nama_ayah ?? null
                    );

                $s->pekerjaan_ayah =
                    $this->normalizeText(
                        $s->pekerjaan_ayah ?? null
                    );

                $s->nama_ibu =
                    $this->normalizeText(
                        $s->nama_ibu ?? null
                    );

                $s->pekerjaan_ibu =
                    $this->normalizeText(
                        $s->pekerjaan_ibu ?? null
                    );

                $s->nama_wali =
                    $this->normalizeText(
                        $s->nama_wali ?? null
                    );

                $s->pekerjaan_wali =
                    $this->normalizeText(
                        $s->pekerjaan_wali ?? null
                    );

                $s->sekolah_asal =
                    $this->normalizeText(
                        $s->sekolah_asal ?? null
                    );

                $s->jenis_pendaftaran =
                    $this->normalizeText(
                        $s->jenis_pendaftaran ?? null
                    );

                $s->kebutuhan_khusus =
                    $this->normalizeText(
                        $s->kebutuhan_khusus ?? null
                    );

                return $s;
            }
        );


        /*
         * ========================================================
         * DAFTAR ROMBEL REGULER
         * ========================================================
         */
        $rombels = DB::table('homerooms')
            ->where(
                'jenis_rombel',
                'not like',
                '%Ekstrakurikuler%'
            )
            ->where(
                'jenis_rombel',
                'not like',
                '%Pilihan%'
            )
            ->orderBy('nama')
            ->get();


        return view(
            'siswa.index',
            compact(
                'students',
                'sort',
                'direction',
                'rombels',
                'search'
            )
        );
    }


    /**
     * ============================================================
     * TAMBAH SISWA
     * ============================================================
     */
    public function store(
        Request $request
    ): RedirectResponse {

        $request->validate([

            'nisn' =>
                'required|string|regex:/^[0-9]{10}$/|unique:students,nisn',

            'nipd' =>
                'nullable|string',

            'nama' =>
                'required|string',

            'jenis_kelamin' =>
                'required|in:L,P',

            'homeroom_id' =>
                'nullable|exists:homerooms,id'
        ]);


        DB::transaction(
            function () use ($request) {

                $studentId =
                    DB::table('students')
                        ->insertGetId([

                            'dapodik_peserta_didik_id' =>
                                'manual-' . Str::uuid(),

                            'nisn' =>
                                $request->nisn,

                            'nipd' =>
                                $request->nipd,

                            'nama' =>
                                $this->normalizeText(
                                    $request->nama
                                ),

                            'jenis_kelamin' =>
                                $request->jenis_kelamin,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);


                if ($request->homeroom_id) {

                    DB::table('enrollments')
                        ->insert([

                            'dapodik_anggota_rombel_id' =>
                                'manual-' . Str::uuid(),

                            'student_id' =>
                                $studentId,

                            'homeroom_id' =>
                                $request->homeroom_id,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);
                }

                app(\App\Services\Sync\ManualCloudDirectoryAccountsV21R80::class)
                    ->provisionStudent((int) $studentId);
            }
        );


        return redirect()
            ->back()
            ->with(
                'success',
                'Data murid dan akun lokal berhasil disiapkan; Cloud akan diperbarui saat sinkronisasi.'
            );
    }


    /**
     * ============================================================
     * UPDATE SISWA
     * ============================================================
     */
    public function update(
        Request $request,
        $id
    ): RedirectResponse {

        $request->validate([

            'nisn' =>
                'required|string|regex:/^[0-9]{10}$/|unique:students,nisn,' . $id,

            'nipd' =>
                'nullable|string',

            'nama' =>
                'required|string',

            'jenis_kelamin' =>
                'required|in:L,P',

            'homeroom_id' =>
                'nullable|exists:homerooms,id'
        ]);


        DB::transaction(
            function () use (
                $request,
                $id
            ) {

                app(\App\Services\Sync\ManualCloudDirectoryAccountsV21R80::class)
                    ->assertStudentIdentityNotRotated((int) $id, (string) $request->nisn);

                DB::table('students')
                    ->where(
                        'id',
                        $id
                    )
                    ->update([

                        'nisn' =>
                            $request->nisn,

                        'nipd' =>
                            $request->nipd,

                        'nama' =>
                            $this->normalizeText(
                                $request->nama
                            ),

                        'jenis_kelamin' =>
                            $request->jenis_kelamin,

                        'updated_at' =>
                            now(),
                    ]);


                $existingEnrollment =
                    DB::table('enrollments')
                        ->join(
                            'homerooms',
                            'enrollments.homeroom_id',
                            '=',
                            'homerooms.id'
                        )
                        ->where(
                            'enrollments.student_id',
                            $id
                        )
                        ->where(
                            'homerooms.jenis_rombel',
                            'not like',
                            '%Ekstrakurikuler%'
                        )
                        ->where(
                            'homerooms.jenis_rombel',
                            'not like',
                            '%Pilihan%'
                        )
                        ->select(
                            'enrollments.id'
                        )
                        ->first();


                if ($existingEnrollment) {

                    if ($request->homeroom_id) {

                        DB::table('enrollments')
                            ->where(
                                'id',
                                $existingEnrollment->id
                            )
                            ->update([

                                'homeroom_id' =>
                                    $request->homeroom_id,

                                'updated_at' =>
                                    now(),
                            ]);

                    } else {

                        DB::table('enrollments')
                            ->where(
                                'id',
                                $existingEnrollment->id
                            )
                            ->delete();
                    }

                } elseif ($request->homeroom_id) {

                    DB::table('enrollments')
                        ->insert([

                            'dapodik_anggota_rombel_id' =>
                                'manual-' . Str::uuid(),

                            'student_id' =>
                                $id,

                            'homeroom_id' =>
                                $request->homeroom_id,

                            'created_at' =>
                                now(),

                            'updated_at' =>
                                now(),
                        ]);
                }

                app(\App\Services\Sync\ManualCloudDirectoryAccountsV21R80::class)
                    ->provisionStudent((int) $id);
            }
        );


        return redirect()
            ->back()
            ->with(
                'success',
                'Data murid diperbarui; sinkronisasi direktori Cloud dijadwalkan.'
            );
    }


    /**
     * ============================================================
     * HAPUS SISWA
     * ============================================================
     */
    public function destroy(
        $id
    ): RedirectResponse {

        DB::table('students')
            ->where(
                'id',
                $id
            )
            ->delete();


        return redirect()
            ->back()
            ->with(
                'success',
                'Data murid berhasil dihapus!'
            );
    }


    /**
     * ============================================================
     * EXPORT DATA SISWA MODEL 8355
     * ============================================================
     */
    public function export(
        Request $request
    ) {

        $search =
            $request->get(
                'search',
                ''
            );

        $sort =
            $request->get(
                'sort',
                'nama'
            );

        $direction =
            $request->get(
                'direction',
                'asc'
            );


        $allowedSort = [

            'nipd' =>
                'students.nipd',

            'nisn' =>
                'students.nisn',

            'nama' =>
                'students.nama',

            'jenis_kelamin' =>
                'students.jenis_kelamin',

            'kelas' =>
                'homerooms.nama'
        ];


        if (!array_key_exists(
            $sort,
            $allowedSort
        )) {
            $sort = 'nama';
        }


        $sortCol =
            $allowedSort[$sort];


        if (!in_array(
            $direction,
            ['asc', 'desc']
        )) {
            $direction = 'asc';
        }


        /**
         * ========================================================
         * DATA SISWA
         * ========================================================
         */
        $students = DB::table('students')
            ->join(
                'enrollments',
                'students.id',
                '=',
                'enrollments.student_id'
            )
            ->join(
                'homerooms',
                function ($join) {

                    $join->on(
                        'enrollments.homeroom_id',
                        '=',
                        'homerooms.id'
                    )
                    ->where(
                        'homerooms.jenis_rombel',
                        'not like',
                        '%Ekstrakurikuler%'
                    )
                    ->where(
                        'homerooms.jenis_rombel',
                        'not like',
                        '%Pilihan%'
                    );
                }
            )
            ->select(
                'students.*',
                'homerooms.nama as kelas'
            )
            ->when(
                $search,
                function ($query, $search) {
                    app(\App\Services\UniversalColumnSearchService::class)
                        ->apply(
                            $query,
                            $search,
                            [
                                'students',
                                'homerooms',
                                'enrollments',
                            ]
                        );
                }
            )
            ->orderBy(
                $sortCol,
                $direction
            )
            ->get();


        /**
         * ========================================================
         * DATA SEKOLAH
         * ========================================================
         */
        $school =
            DB::table('schools')
                ->first();


        /**
         * ========================================================
         * KEPALA SEKOLAH
         * ========================================================
         */
        $kepalaSekolah =
            DB::table('teachers')
                ->where(
                    function ($query) {

                        $query
                            ->where(
                                'jabatan_ptk',
                                'like',
                                '%Kepala Sekolah%'
                            )
                            ->orWhere(
                                'jenis_ptk',
                                'like',
                                '%Kepala Sekolah%'
                            );
                    }
                )
                ->orderBy(
                    'nama',
                    'asc'
                )
                ->first();


        /**
         * ========================================================
         * NAMA KEPALA SEKOLAH
         * ========================================================
         */
        $namaKepalaSekolah = '-';


        if ($kepalaSekolah) {

            if (
                isset(
                    $kepalaSekolah->nama_lengkap
                )
                &&
                trim(
                    (string)
                    $kepalaSekolah->nama_lengkap
                ) !== ''
            ) {

                $namaKepalaSekolah =
                    trim(
                        $kepalaSekolah->nama_lengkap
                    );

            } else {

                $namaKepalaSekolah =
                    $this->generateNamaLengkap(
                        $kepalaSekolah->gelar_depan ?? '',
                        $kepalaSekolah->nama ?? '',
                        $kepalaSekolah->gelar_belakang ?? ''
                    );


                if (
                    trim(
                        (string)
                        $namaKepalaSekolah
                    ) === ''
                ) {
                    $namaKepalaSekolah = '-';
                }
            }
        }


        /**
         * ========================================================
         * NIP KEPALA SEKOLAH
         * ========================================================
         */
        $nipKepalaSekolah =
            $kepalaSekolah->nip
            ?? '-';


        /**
         * ========================================================
         * KOP SURAT
         * ========================================================
         */
        $kopSuratPath =
            DB::table('app_settings')
                ->where(
                    'key',
                    'kop_surat'
                )
                ->value('value');


        /**
         * ========================================================
         * DATA EXCEL
         * ========================================================
         *
         * A:O = 15 kolom.
         *
         * Baris 1-6  : Kop
         * Baris 7    : Judul
         * Baris 8-13 : Identitas sekolah
         * Baris 14   : Jeda
         * Baris 15   : Header tabel
         * Setelahnya  : Data siswa
         * Setelah data : Blok tanda tangan
         */
        $dataArray = [];


        /**
         * ========================================================
         * KOP SURAT
         * ========================================================
         */
        for (
            $row = 1;
            $row <= 6;
            $row++
        ) {

            $dataArray[] =
                array_fill(
                    0,
                    15,
                    ''
                );
        }


        /**
         * ========================================================
         * JUDUL
         * ========================================================
         */
        $dataArray[] = [

            'DATA MURID MODEL 8355',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        /**
         * ========================================================
         * IDENTITAS SEKOLAH
         * ========================================================
         */
        $dataArray[] = [

            'Nama Sekolah',
            ':',
            $school->nama ?? '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        $dataArray[] = [

            'NPSN',
            ':',
            !empty($school->npsn)
                ? (string) $school->npsn
                : '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        $dataArray[] = [

            'Alamat Sekolah',
            ':',
            $school->alamat
                ?? $school->alamat_jalan
                ?? '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        $dataArray[] = [

            'Kecamatan',
            ':',
            $school->kecamatan ?? '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        $dataArray[] = [

            'Kabupaten',
            ':',
            $school->kabupaten
                ?? $school->kabupaten_kota
                ?? '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        $dataArray[] = [

            'Provinsi',
            ':',
            $school->provinsi ?? '-',

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            ''
        ];


        /**
         * ========================================================
         * JEDA
         * ========================================================
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /**
         * ========================================================
         * HEADER TABEL
         * ========================================================
         */
        $headerRow =
            count($dataArray) + 1;


        $dataArray[] = [

            'No.',
            'NIS',
            'NISN',
            'NIK',
            'Nama Murid',
            'Kelas',
            'Jenis Kelamin',
            'Tempat Lahir',
            'Tanggal Lahir',
            'Agama',
            'Nama Ayah',
            'Pekerjaan Ayah',
            'Nama Ibu',
            'Pekerjaan Ibu',
            'Alamat Murid'
        ];


        /**
         * ========================================================
         * DATA SISWA
         * ========================================================
         */
        $nomor = 1;


        foreach ($students as $s) {

            $nis =
                !empty($s->nipd)
                    ? (string) $s->nipd
                    : '-';


            $nisn =
                !empty($s->nisn)
                    ? (string) $s->nisn
                    : '-';


            $nik =
                !empty($s->nik)
                    ? (string) $s->nik
                    : '-';


            $nama =
                $this->normalizeText(
                    $s->nama ?? ''
                );


            if (
                trim(
                    (string) $nama
                ) === ''
            ) {
                $nama = '-';
            }


            $tempatLahir =
                $this->normalizeText(
                    $s->tempat_lahir ?? ''
                );


            if (
                trim(
                    (string) $tempatLahir
                ) === ''
            ) {
                $tempatLahir = '-';
            }


            $tanggalLahir = '-';


            if (
                !empty(
                    $s->tanggal_lahir
                )
            ) {

                try {

                    $tanggalLahir =
                        Carbon::parse(
                            $s->tanggal_lahir
                        )->format(
                            'd/m/Y'
                        );

                } catch (
                    \Throwable $e
                ) {

                    $tanggalLahir =
                        (string)
                        $s->tanggal_lahir;
                }
            }


            $agama =
                $this->normalizeText(
                    $s->agama ?? ''
                );


            if (
                trim(
                    (string) $agama
                ) === ''
            ) {
                $agama = '-';
            }


            $namaAyah =
                $this->normalizeText(
                    $s->nama_ayah ?? ''
                );


            if (
                trim(
                    (string) $namaAyah
                ) === ''
            ) {
                $namaAyah = '-';
            }


            $pekerjaanAyah =
                $this->normalizeText(
                    $s->pekerjaan_ayah ?? ''
                );


            if (
                trim(
                    (string) $pekerjaanAyah
                ) === ''
            ) {
                $pekerjaanAyah = '-';
            }


            $namaIbu =
                $this->normalizeText(
                    $s->nama_ibu ?? ''
                );


            if (
                trim(
                    (string) $namaIbu
                ) === ''
            ) {
                $namaIbu = '-';
            }


            $pekerjaanIbu =
                $this->normalizeText(
                    $s->pekerjaan_ibu ?? ''
                );


            if (
                trim(
                    (string) $pekerjaanIbu
                ) === ''
            ) {
                $pekerjaanIbu = '-';
            }


            $alamat =
                $this->normalizeText(
                    $s->alamat_jalan ?? ''
                );


            if (
                trim(
                    (string) $alamat
                ) === ''
            ) {
                $alamat = '-';
            }


            $dataArray[] = [

                $nomor,

                (string) $nis,

                (string) $nisn,

                (string) $nik,

                $nama,

                !empty($s->kelas)
                    ? (string) $s->kelas
                    : '-',

                !empty($s->jenis_kelamin)
                    ? (string) $s->jenis_kelamin
                    : '-',

                $tempatLahir,

                $tanggalLahir,

                $agama,

                $namaAyah,

                $pekerjaanAyah,

                $namaIbu,

                $pekerjaanIbu,

                $alamat
            ];


            $nomor++;
        }


        /**
         * ========================================================
         * BLOK TANDA TANGAN
         * ========================================================
         *
         * TOTAL 10 BARIS:
         *
         * 1. Jeda
         * 2. Jeda
         * 3. Tempat, tanggal
         * 4. Jeda
         * 5. Kepala Sekolah
         * 6. Jeda
         * 7. Jeda
         * 8. Jeda
         * 9. Nama Kepala Sekolah
         * 10. NIP
         *
         * Nilai dimasukkan ke KOLOM L.
         *
         * Kemudian L:O di-merge.
         *
         * Ini penting karena setelah merge,
         * nilai pada sel selain L akan hilang.
         */

        $signatureStartRow =
            count($dataArray) + 1;


        /*
         * BARIS 1
         * Jeda
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 2
         * Jeda
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 3
         * TEMPAT DAN TANGGAL
         *
         * Nilai berada di INDEX 11 = kolom L.
         */
        $tanggalCetak =
            Carbon::now()
                ->locale('id')
                ->translatedFormat(
                    'j F Y'
                );


        $tempatCetak =
            $school->kecamatan
            ?? 'Hulu Gurung';


        $dataArray[] = [

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $tempatCetak .
                ', ' .
                $tanggalCetak,
            '',
            '',
            ''
        ];


        /*
         * BARIS 4
         * Jeda
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 5
         * JABATAN
         */
        $dataArray[] = [

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'Kepala Sekolah',
            '',
            '',
            ''
        ];


        /*
         * BARIS 6
         * Jeda tanda tangan
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 7
         * Jeda tanda tangan
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 8
         * Jeda tanda tangan
         */
        $dataArray[] =
            array_fill(
                0,
                15,
                ''
            );


        /*
         * BARIS 9
         * NAMA KEPALA SEKOLAH
         */
        $dataArray[] = [

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            $namaKepalaSekolah,
            '',
            '',
            ''
        ];


        /*
         * BARIS 10
         * NIP
         */
        $dataArray[] = [

            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            '',
            'NIP. ' .
                (string) $nipKepalaSekolah,
            '',
            '',
            ''
        ];


        /**
         * ========================================================
         * EXPORT CLASS
         * ========================================================
         */
        $export = new class(
            $dataArray,
            $headerRow,
            $kopSuratPath,
            $signatureStartRow
        ) implements FromArray, WithEvents {

            private $data;
            private $headerRow;
            private $kopSuratPath;
            private $signatureStartRow;


            public function __construct(
                $data,
                $headerRow,
                $kopSuratPath,
                $signatureStartRow
            ) {

                $this->data =
                    $data;

                $this->headerRow =
                    $headerRow;

                $this->kopSuratPath =
                    $kopSuratPath;

                $this->signatureStartRow =
                    $signatureStartRow;
            }


            public function array(): array
            {
                return $this->data;
            }


            public function registerEvents(): array
            {
                return [

                    AfterSheet::class =>
                        function (
                            AfterSheet $event
                        ) {

                            $sheet =
                                $event
                                    ->sheet
                                    ->getDelegate();


                            /*
                             * =================================================
                             * KOLOM TERAKHIR
                             * =================================================
                             */
                            $lastColumn = 'O';


                            /*
                             * =================================================
                             * KOLOM AREA TANDA TANGAN
                             * =================================================
                             */
                            $signatureStartColumn = 'L';
                            $signatureEndColumn = 'O';


                            /*
                             * =================================================
                             * KOP SURAT
                             * =================================================
                             */
                            if (
                                !empty(
                                    $this->kopSuratPath
                                )
                            ) {

                                $fullPath =
                                    Storage::disk(
                                        'public'
                                    )->path(
                                        $this->kopSuratPath
                                    );


                                if (
                                    file_exists(
                                        $fullPath
                                    )
                                ) {

                                    try {

                                        $drawing =
                                            new Drawing();


                                        $drawing
                                            ->setName(
                                                'Kop Surat'
                                            );


                                        $drawing
                                            ->setDescription(
                                                'Kop Surat Sekolah'
                                            );


                                        $drawing
                                            ->setPath(
                                                $fullPath
                                            );


                                        $drawing
                                            ->setHeight(
                                                100
                                            );


                                        $drawing
                                            ->setCoordinates(
                                                'A1'
                                            );


                                        $drawing
                                            ->setOffsetX(
                                                10
                                            );


                                        $drawing
                                            ->setOffsetY(
                                                5
                                            );


                                        $drawing
                                            ->setWorksheet(
                                                $sheet
                                            );

                                    } catch (
                                        \Throwable $e
                                    ) {
                                    }
                                }
                            }


                            /*
                             * =================================================
                             * TINGGI BARIS KOP
                             * =================================================
                             */
                            for (
                                $row = 1;
                                $row <= 6;
                                $row++
                            ) {

                                $sheet
                                    ->getRowDimension(
                                        $row
                                    )
                                    ->setRowHeight(
                                        25
                                    );
                            }


                            /*
                             * =================================================
                             * JUDUL
                             * =================================================
                             */
                            $sheet
                                ->mergeCells(
                                    'A7:O7'
                                );


                            $sheet
                                ->getStyle(
                                    'A7:O7'
                                )
                                ->getFont()
                                ->setBold(
                                    true
                                )
                                ->setSize(
                                    16
                                );


                            $sheet
                                ->getStyle(
                                    'A7:O7'
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    Alignment::HORIZONTAL_CENTER
                                )
                                ->setVertical(
                                    Alignment::VERTICAL_CENTER
                                );


                            $sheet
                                ->getRowDimension(
                                    7
                                )
                                ->setRowHeight(
                                    25
                                );


                            /*
                             * =================================================
                             * BLOK IDENTITAS SEKOLAH
                             * =================================================
                             *
                             * TANPA BORDER.
                             */
                            for (
                                $row = 8;
                                $row <= 13;
                                $row++
                            ) {

                                /*
                                 * Merge C:O.
                                 */
                                $sheet
                                    ->mergeCells(
                                        'C' .
                                        $row .
                                        ':O' .
                                        $row
                                    );


                                /*
                                 * Label.
                                 */
                                $sheet
                                    ->getStyle(
                                        'A' .
                                        $row
                                    )
                                    ->getFont()
                                    ->setBold(
                                        true
                                    );


                                /*
                                 * Titik dua.
                                 */
                                $sheet
                                    ->getStyle(
                                        'B' .
                                        $row
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_CENTER
                                    );


                                /*
                                 * Nilai.
                                 */
                                $sheet
                                    ->getStyle(
                                        'C' .
                                        $row .
                                        ':O' .
                                        $row
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_LEFT
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_CENTER
                                    )
                                    ->setWrapText(
                                        true
                                    );


                                $sheet
                                    ->getRowDimension(
                                        $row
                                    )
                                    ->setRowHeight(
                                        25
                                    );
                            }


                            /*
                             * PENTING:
                             *
                             * Tidak memberikan border
                             * kepada A8:O13.
                             */


                            /*
                             * =================================================
                             * JEDA
                             * =================================================
                             */
                            $sheet
                                ->getRowDimension(
                                    14
                                )
                                ->setRowHeight(
                                    25
                                );


                            /*
                             * =================================================
                             * HEADER TABEL SISWA
                             * =================================================
                             */
                            $headerRange =
                                'A' .
                                $this->headerRow .
                                ':O' .
                                $this->headerRow;


                            $sheet
                                ->getStyle(
                                    $headerRange
                                )
                                ->getFont()
                                ->setBold(
                                    true
                                );


                            $sheet
                                ->getStyle(
                                    $headerRange
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    Alignment::HORIZONTAL_CENTER
                                )
                                ->setVertical(
                                    Alignment::VERTICAL_CENTER
                                )
                                ->setWrapText(
                                    true
                                );


                            $sheet
                                ->getStyle(
                                    $headerRange
                                )
                                ->getFill()
                                ->setFillType(
                                    Fill::FILL_SOLID
                                )
                                ->getStartColor()
                                ->setARGB(
                                    'FFE5E7EB'
                                );


                            $sheet
                                ->getRowDimension(
                                    $this->headerRow
                                )
                                ->setRowHeight(
                                    25
                                );


                            /*
                             * =================================================
                             * BARIS TERAKHIR
                             * =================================================
                             */
                            $highestRow =
                                $sheet
                                    ->getHighestRow();


                            /*
                             * =================================================
                             * AREA DATA SISWA
                             * =================================================
                             */
                            $firstStudentRow =
                                $this->headerRow + 1;


                            /*
                             * Baris terakhir siswa adalah
                             * tepat sebelum area tanda tangan.
                             */
                            $lastStudentRow =
                                $this->signatureStartRow - 1;


                            /*
                             * =================================================
                             * BORDER TABEL SISWA SAJA
                             * =================================================
                             */
                            if (
                                $lastStudentRow >=
                                $this->headerRow
                            ) {

                                $tableRange =
                                    'A' .
                                    $this->headerRow .
                                    ':O' .
                                    $lastStudentRow;


                                $sheet
                                    ->getStyle(
                                        $tableRange
                                    )
                                    ->getBorders()
                                    ->getAllBorders()
                                    ->setBorderStyle(
                                        Border::BORDER_THIN
                                    );
                            }


                            /*
                             * =================================================
                             * ALIGNMENT DATA SISWA
                             * =================================================
                             */
                            if (
                                $lastStudentRow >=
                                $firstStudentRow
                            ) {

                                /*
                                 * NOMOR
                                 */
                                $sheet
                                    ->getStyle(
                                        'A' .
                                        $firstStudentRow .
                                        ':A' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_CENTER
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    );


                                /*
                                 * NIS / NISN / NIK
                                 */
                                $sheet
                                    ->getStyle(
                                        'B' .
                                        $firstStudentRow .
                                        ':D' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_LEFT
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    );


                                $sheet
                                    ->getStyle(
                                        'B' .
                                        $firstStudentRow .
                                        ':D' .
                                        $lastStudentRow
                                    )
                                    ->getNumberFormat()
                                    ->setFormatCode(
                                        '@'
                                    );


                                /*
                                 * KELAS / JK
                                 */
                                $sheet
                                    ->getStyle(
                                        'F' .
                                        $firstStudentRow .
                                        ':G' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_CENTER
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    );


                                /*
                                 * TANGGAL LAHIR
                                 */
                                $sheet
                                    ->getStyle(
                                        'I' .
                                        $firstStudentRow .
                                        ':I' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_CENTER
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    );


                                /*
                                 * NAMA SISWA
                                 */
                                $sheet
                                    ->getStyle(
                                        'E' .
                                        $firstStudentRow .
                                        ':E' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_LEFT
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    )
                                    ->setWrapText(
                                        true
                                    );


                                /*
                                 * KOLOM LAIN
                                 */
                                $sheet
                                    ->getStyle(
                                        'H' .
                                        $firstStudentRow .
                                        ':O' .
                                        $lastStudentRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        Alignment::HORIZONTAL_LEFT
                                    )
                                    ->setVertical(
                                        Alignment::VERTICAL_TOP
                                    )
                                    ->setWrapText(
                                        true
                                    );


                                /*
                                 * PAKSA NIS/NISN/NIK
                                 * MENJADI STRING.
                                 */
                                for (
                                    $row =
                                        $firstStudentRow;
                                    $row <=
                                        $lastStudentRow;
                                    $row++
                                ) {

                                    foreach (
                                        ['B', 'C', 'D']
                                        as $column
                                    ) {

                                        $cell =
                                            $sheet->getCell(
                                                $column .
                                                $row
                                            );


                                        $value =
                                            $cell->getValue();


                                        if (
                                            $value !== null
                                            &&
                                            $value !== ''
                                        ) {

                                            $cell
                                                ->setValueExplicit(
                                                    (string) $value,
                                                    DataType::TYPE_STRING
                                                );
                                        }
                                    }
                                }


                                /*
                                 * =================================================
                                 * TINGGI BARIS SISWA
                                 * =================================================
                                 */
                                for (
                                    $row =
                                        $firstStudentRow;
                                    $row <=
                                        $lastStudentRow;
                                    $row++
                                ) {

                                    $sheet
                                        ->getRowDimension(
                                            $row
                                        )
                                        ->setRowHeight(
                                            30
                                        );
                                }
                            }


                            /*
                             * =================================================
                             * BLOK TANDA TANGAN
                             * =================================================
                             *
                             * Posisi:
                             *
                             * L:O
                             *
                             * Tidak diberi border.
                             *
                             * Semua isi center.
                             */
                            $signatureEndRow =
                                $highestRow;


                            $signatureRange =
                                $signatureStartColumn .
                                $this->signatureStartRow .
                                ':' .
                                $signatureEndColumn .
                                $signatureEndRow;


                            $sheet
                                ->getStyle(
                                    $signatureRange
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    Alignment::HORIZONTAL_CENTER
                                )
                                ->setVertical(
                                    Alignment::VERTICAL_CENTER
                                )
                                ->setWrapText(
                                    true
                                );


                            /*
                             * =================================================
                             * MERGE SETIAP BARIS L:O
                             * =================================================
                             *
                             * Nilai sudah diletakkan di L.
                             *
                             * Karena L adalah sel kiri-atas,
                             * nilai akan tetap muncul setelah merge.
                             */
                            for (
                                $row =
                                    $this->signatureStartRow;
                                $row <=
                                    $signatureEndRow;
                                $row++
                            ) {

                                $sheet
                                    ->mergeCells(
                                        'L' .
                                        $row .
                                        ':O' .
                                        $row
                                    );
                            }


                            /*
                             * =================================================
                             * TEMPAT DAN TANGGAL
                             * =================================================
                             */
                            $tanggalRow =
                                $this->signatureStartRow + 2;


                            $sheet
                                ->getStyle(
                                    'L' .
                                    $tanggalRow .
                                    ':O' .
                                    $tanggalRow
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    Alignment::HORIZONTAL_CENTER
                                );


                            /*
                             * =================================================
                             * JABATAN
                             * =================================================
                             */
                            $jabatanRow =
                                $this->signatureStartRow + 4;


                            $sheet
                                ->getStyle(
                                    'L' .
                                    $jabatanRow .
                                    ':O' .
                                    $jabatanRow
                                )
                                ->getFont()
                                ->setBold(
                                    false
                                );


                            /*
                             * =================================================
                             * NAMA KEPALA SEKOLAH
                             * =================================================
                             */
                            $namaRow =
                                $this->signatureStartRow + 8;


                            $sheet
                                ->getStyle(
                                    'L' .
                                    $namaRow .
                                    ':O' .
                                    $namaRow
                                )
                                ->getFont()
                                ->setBold(
                                    true
                                );


                            /*
                             * =================================================
                             * NIP
                             * =================================================
                             */
                            $nipRow =
                                $this->signatureStartRow + 9;


                            $sheet
                                ->getStyle(
                                    'L' .
                                    $nipRow .
                                    ':O' .
                                    $nipRow
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    Alignment::HORIZONTAL_CENTER
                                );


                            /*
                             * =================================================
                             * TINGGI BARIS TANDA TANGAN
                             * =================================================
                             */
                            $sheet
                                ->getRowDimension(
                                    $this->signatureStartRow
                                )
                                ->setRowHeight(
                                    20
                                );


                            $sheet
                                ->getRowDimension(
                                    $tanggalRow
                                )
                                ->setRowHeight(
                                    20
                                );


                            $sheet
                                ->getRowDimension(
                                    $jabatanRow
                                )
                                ->setRowHeight(
                                    20
                                );


                            /*
                             * Ruang tanda tangan.
                             */
                            for (
                                $row =
                                    $jabatanRow + 1;
                                $row <=
                                    ($namaRow - 1);
                                $row++
                            ) {

                                $sheet
                                    ->getRowDimension(
                                        $row
                                    )
                                    ->setRowHeight(
                                        20
                                    );
                            }


                            /*
                             * Nama dan NIP.
                             */
                            $sheet
                                ->getRowDimension(
                                    $namaRow
                                )
                                ->setRowHeight(
                                    20
                                );


                            $sheet
                                ->getRowDimension(
                                    $nipRow
                                )
                                ->setRowHeight(
                                    20
                                );


                            /*
                             * =================================================
                             * LEBAR KOLOM
                             * =================================================
                             */
                            $widths = [

                                'A' => 6,

                                'B' => 13,

                                'C' => 16,

                                'D' => 20,

                                'E' => 28,

                                'F' => 17,

                                'G' => 14,

                                'H' => 22,

                                'I' => 16,

                                'J' => 16,

                                'K' => 25,

                                'L' => 24,

                                'M' => 25,

                                'N' => 24,

                                'O' => 42
                            ];


                            foreach (
                                $widths
                                as $column =>
                                $width
                            ) {

                                $sheet
                                    ->getColumnDimension(
                                        $column
                                    )
                                    ->setWidth(
                                        $width
                                    );
                            }


                            /*
                             * =================================================
                             * SETTING CETAK
                             * =================================================
                             */
                            $sheet
                                ->getPageSetup()
                                ->setOrientation(
                                    PageSetup::ORIENTATION_LANDSCAPE
                                );


                            $sheet
                                ->getPageSetup()
                                ->setPaperSize(
                                    PageSetup::PAPERSIZE_A4
                                );


                            $sheet
                                ->getPageSetup()
                                ->setFitToWidth(
                                    1
                                );


                            $sheet
                                ->getPageSetup()
                                ->setFitToHeight(
                                    0
                                );


                            $sheet
                                ->getPageSetup()
                                ->setHorizontalCentered(
                                    false
                                );


                            /*
                             * =================================================
                             * HEADER DIULANG SAAT PRINT
                             * =================================================
                             */
                            $sheet
                                ->getPageSetup()
                                ->setRowsToRepeatAtTopByStartAndEnd(
                                    $this->headerRow,
                                    $this->headerRow
                                );


                            /*
                             * =================================================
                             * MARGIN
                             * =================================================
                             */
                            $sheet
                                ->getPageMargins()
                                ->setTop(
                                    0.4
                                );


                            $sheet
                                ->getPageMargins()
                                ->setRight(
                                    0.3
                                );


                            $sheet
                                ->getPageMargins()
                                ->setBottom(
                                    0.4
                                );


                            $sheet
                                ->getPageMargins()
                                ->setLeft(
                                    0.3
                                );


                            $sheet
                                ->getPageMargins()
                                ->setHeader(
                                    0.2
                                );


                            $sheet
                                ->getPageMargins()
                                ->setFooter(
                                    0.2
                                );


                            /*
                             * =================================================
                             * FOOTER
                             * =================================================
                             */
                            $sheet
                                ->getHeaderFooter()
                                ->setOddFooter(
                                    '&CHalaman &P dari &N'
                                );


                            /*
                             * =================================================
                             * FREEZE PANE
                             * =================================================
                             *
                             * SENGAJA TIDAK ADA.
                             *
                             * Tidak menggunakan:
                             *
                             * freezePane()
                             */


                            /*
                             * =================================================
                             * AREA CETAK
                             * =================================================
                             */
                            $sheet
                                ->getPageSetup()
                                ->setPrintArea(
                                    'A1:O' .
                                    $highestRow
                                );


                            /*
                             * =================================================
                             * GRIDLINES
                             * =================================================
                             */
                            $sheet
                                ->setShowGridlines(
                                    false
                                );
                        }
                ];
            }
        };


        /**
         * ========================================================
         * NAMA FILE
         * ========================================================
         */
        $filename =
            'Data_8355_Siswa_' .
            date('Y-m-d') .
            '.xlsx';


        /**
         * ========================================================
         * DOWNLOAD
         * ========================================================
         */
        return Excel::download(
            $export,
            $filename
        );
    }
}
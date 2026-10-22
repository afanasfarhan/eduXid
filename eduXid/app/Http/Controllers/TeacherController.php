<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithEvents;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Events\AfterSheet;

class TeacherController extends Controller
{
    /**
     * Helper untuk memformat Pangkat/Golongan menjadi lengkap
     */
    private function formatPangkat($pangkatGolongan)
    {
        $pangkatGolonganNormal = preg_replace(
            '/\s+/',
            ' ',
            trim($pangkatGolongan ?? '')
        );

        $daftarPangkatSingkat = [
            'I/a'   => 'Juru Muda',
            'I/b'   => 'Juru Muda Tk. I',
            'I/c'   => 'Juru',
            'I/d'   => 'Juru Tk. I',
            'II/a'  => 'Pengatur Muda',
            'II/b'  => 'Pengatur Muda Tk. I',
            'II/c'  => 'Pengatur',
            'II/d'  => 'Pengatur Tk. I',
            'III/a' => 'Penata Muda',
            'III/b' => 'Penata Muda Tk. I',
            'III/c' => 'Penata',
            'III/d' => 'Penata Tk. I',
            'IV/a'  => 'Pembina',
            'IV/b'  => 'Pembina Tk. I',
            'IV/c'  => 'Pembina Utama Muda',
            'IV/d'  => 'Pembina Utama Madya',
            'IV/e'  => 'Pembina Utama',
        ];

        foreach ($daftarPangkatSingkat as $kode => $nama) {
            if (strcasecmp($pangkatGolonganNormal, $kode) === 0) {
                return $nama . ', ' . $kode;
            }
        }

        return $pangkatGolonganNormal !== ''
            ? $pangkatGolonganNormal
            : '-';
    }

    /**
     * Helper untuk mengubah teks menjadi format Title Case
     * dengan pengecualian singkatan.
     *
     * Contoh:
     * MAwan -> Mawan
     * MAWAN -> Mawan
     * mawan -> Mawan
     * mAWAN -> Mawan
     */
    private function normalizeText($text)
    {
        if ($text === null || trim((string) $text) === '') {
            return $text;
        }

        // Bersihkan spasi berlebih
        $text = preg_replace(
            '/\s+/',
            ' ',
            trim((string) $text)
        );

        // Ubah seluruh teks menjadi huruf kecil terlebih dahulu
        $text = mb_strtolower($text, 'UTF-8');

        // Ubah menjadi Title Case
        $text = mb_convert_case(
            $text,
            MB_CASE_TITLE,
            'UTF-8'
        );

        // Singkatan yang harus tetap menggunakan huruf kapital
        $abbreviations = [
            'Pns'  => 'PNS',
            'Pppk' => 'PPPK',
            'Gtt'  => 'GTT',
            'Gty'  => 'GTY',
            'Sd'   => 'SD',
            'Smp'  => 'SMP',
            'Sma'  => 'SMA',
            'Smk'  => 'SMK',
            'Bk'   => 'BK',
            'Mts'  => 'MTs',
            'Mi'   => 'MI',
            'Ma'   => 'MA',
            'Slb'  => 'SLB',
            'Sltp' => 'SLTP',
            'Slta' => 'SLTA',
        ];

        foreach ($abbreviations as $abbr => $replacement) {
            $text = preg_replace(
                '/\b' . preg_quote($abbr, '/') . '\b/u',
                $replacement,
                $text
            );
        }

        return $text;
    }

    /**
     * Helper untuk merangkai nama lengkap dengan gelar
     */
    private function generateNamaLengkap(
        $gelarDepan,
        $nama,
        $gelarBelakang
    ) {
        $namaNormal = $this->normalizeText($nama);

        return trim(
            ($gelarDepan ? $gelarDepan . ' ' : '') .
            $namaNormal .
            ($gelarBelakang ? ', ' . $gelarBelakang : '')
        );
    }

    public function index(Request $request)
    {
        $search = $request->get('search');
        $sort = $request->get('sort', 'nama');
        $direction = $request->get('direction', 'asc');

        $query = DB::table('teachers');

        if ($search) {
            app(\App\Services\UniversalColumnSearchService::class)
                ->apply(
                    $query,
                    $search,
                    ['teachers']
                );
        }

        $allowedSort = [
            'nik',
            'nip',
            'nuptk',
            'nama',
            'jenis_kelamin',
            'jenis_ptk_id_str',
            'jabatan_ptk_id_str',
            'status_kepegawaian_id_str',
            'pangkat_golongan_terakhir'
        ];

        if (!in_array($sort, $allowedSort)) {
            $sort = 'nama';
        }

        if (!in_array(
            strtolower($direction),
            ['asc', 'desc']
        )) {
            $direction = 'asc';
        }

        $teachers = $query
            ->orderBy($sort, $direction)
            ->paginate(15)
            ->appends($request->query());

        // Format teks agar konsisten sebelum dikirim ke View
        $teachers->getCollection()->transform(
            function ($t) {

                $t->pangkat_lengkap = $this->formatPangkat(
                    $t->pangkat_golongan_terakhir
                );

                $t->nama_lengkap = !empty($t->nama_lengkap)
                    ? $t->nama_lengkap
                    : $this->generateNamaLengkap(
                        $t->gelar_depan,
                        $t->nama,
                        $t->gelar_belakang
                    );

                $t->tempat_lahir = $this->normalizeText(
                    $t->tempat_lahir
                );

                $t->agama_id_str = $this->normalizeText(
                    $t->agama_id_str
                );

                $t->jenis_ptk_id_str = $this->normalizeText(
                    $t->jenis_ptk_id_str
                        ?? $t->jenis_ptk
                );

                $t->jabatan_ptk_id_str = $this->normalizeText(
                    $t->jabatan_ptk_id_str
                        ?? $t->jabatan_ptk
                );

                $t->status_kepegawaian_id_str = $this->normalizeText(
                    $t->status_kepegawaian_id_str
                        ?? $t->status_kepegawaian
                );

                $t->pendidikan_terakhir = $this->normalizeText(
                    $t->pendidikan_terakhir
                );

                $t->bidang_studi_terakhir = $this->normalizeText(
                    $t->bidang_studi_terakhir
                );

                return $t;
            }
        );

        return view(
            'guru.index',
            compact(
                'teachers',
                'search',
                'sort',
                'direction'
            )
        );
    }

    public function store(Request $request)
    {
        abort_unless(auth()->user()?->role === 'admin', 403, 'Hanya Admin Sekolah yang dapat mengubah Data GTK.');
        $validated = $request->validate([
            'nik' => 'nullable|string|max:255',
            'gelar_depan' => 'nullable|string|max:255',
            'nama' => 'required|string|max:255',
            'gelar_belakang' => 'nullable|string|max:255',
            'jenis_kelamin' => 'nullable|string|max:1',
            'nuptk' => 'nullable|string|max:255',
            'nip' => 'nullable|string|max:255',
            'jenis_ptk' => 'nullable|string|max:255',
            'jabatan_ptk' => 'nullable|string|max:255',
            'status_kepegawaian' => 'nullable|string|max:255',
            'pangkat_golongan_terakhir' => 'nullable|string|max:255',
        ]);

        foreach (['nip', 'nik', 'nuptk'] as $identityField) {
            $value = trim((string) ($validated[$identityField] ?? ''));
            $validated[$identityField] = $value === '' ? null : $value;
        }

        $validated['nama'] = $this->normalizeText(
            $validated['nama']
        );

        $validated['jenis_ptk'] = $this->normalizeText(
            $validated['jenis_ptk']
        );

        $validated['jabatan_ptk'] = $this->normalizeText(
            $validated['jabatan_ptk']
        );

        $validated['status_kepegawaian'] = $this->normalizeText(
            $validated['status_kepegawaian']
        );

        $validated['nama_lengkap'] =
            $this->generateNamaLengkap(
                $validated['gelar_depan'],
                $validated['nama'],
                $validated['gelar_belakang']
            );

        $manualAccountStatus = DB::transaction(function () use ($validated): string {
            $teacherId = (int) DB::table('teachers')->insertGetId(array_merge(
                $validated,
                ['created_at' => now(), 'updated_at' => now()]
            ));
            return app(\App\Services\Sync\ManualCloudDirectoryAccountsV21R80::class)
                ->provisionTeacher($teacherId);
        });

        return redirect()
            ->route('guru.index')
            ->with(
                'success',
                $manualAccountStatus === 'missing_identity'
                    ? 'Data GTK tersimpan, tetapi akun Cloud belum dapat dibuat: isi NIP atau NIK yang valid.'
                    : 'Data GTK dan akun lokal siap; direktori Cloud akan dipublikasikan saat sinkronisasi.'
            );
    }

    public function update(Request $request, $id)
    {
        abort_unless(auth()->user()?->role === 'admin', 403, 'Hanya Admin Sekolah yang dapat mengubah Data GTK.');
        $validated = $request->validate([
            'nik' => 'nullable|string|max:255',
            'gelar_depan' => 'nullable|string|max:255',
            'nama' => 'required|string|max:255',
            'gelar_belakang' => 'nullable|string|max:255',
            'jenis_kelamin' => 'nullable|string|max:1',
            'nuptk' => 'nullable|string|max:255',
            'nip' => 'nullable|string|max:255',
            'jenis_ptk' => 'nullable|string|max:255',
            'jabatan_ptk' => 'nullable|string|max:255',
            'status_kepegawaian' => 'nullable|string|max:255',
            'pangkat_golongan_terakhir' => 'nullable|string|max:255',
        ]);

        foreach (['nip', 'nik', 'nuptk'] as $identityField) {
            $value = trim((string) ($validated[$identityField] ?? ''));
            $validated[$identityField] = $value === '' ? null : $value;
        }

        $validated['nama'] = $this->normalizeText(
            $validated['nama']
        );

        $validated['jenis_ptk'] = $this->normalizeText(
            $validated['jenis_ptk']
        );

        $validated['jabatan_ptk'] = $this->normalizeText(
            $validated['jabatan_ptk']
        );

        $validated['status_kepegawaian'] = $this->normalizeText(
            $validated['status_kepegawaian']
        );

        $validated['nama_lengkap'] =
            $this->generateNamaLengkap(
                $validated['gelar_depan'],
                $validated['nama'],
                $validated['gelar_belakang']
            );

        DB::transaction(function () use ($id, $validated): void {
            $accounts = app(\App\Services\Sync\ManualCloudDirectoryAccountsV21R80::class);
            $accounts->assertTeacherIdentityNotRotated((int) $id, $validated);
            DB::table('teachers')->where('id', $id)->update(array_merge(
                $validated, ['updated_at' => now()]
            ));
            $accounts->provisionTeacher((int) $id);
        });

        return redirect()
            ->route('guru.index')
            ->with(
                'success',
                'Data GTK berhasil diperbarui.'
            );
    }

    public function destroy($id)
    {
        abort_unless(auth()->user()?->role === 'admin', 403, 'Hanya Admin Sekolah yang dapat mengubah Data GTK.');
        DB::table('teachers')
            ->where('id', $id)
            ->delete();

        return redirect()
            ->route('guru.index')
            ->with(
                'success',
                'Data GTK berhasil dihapus.'
            );
    }

    /**
     * Export Data GTK ke Excel.
     *
     * Kolom:
     * A = No.
     * B = NIP
     * C = NUPTK
     * D = NIK
     *
     * NIP, NUPTK dan NIK dipertahankan sebagai TEXT.
     */
    public function export(Request $request)
    {
        $includeSensitive = auth()->user()?->role === 'admin' && $request->boolean('include_sensitive');
        $search = $request->get('search');
        $sort = $request->get('sort', 'nama');
        $direction = $request->get('direction', 'asc');

        $query = DB::table('teachers');

        if ($search) {
            app(\App\Services\UniversalColumnSearchService::class)
                ->apply(
                    $query,
                    $search,
                    ['teachers']
                );
        }

        $allowedSort = [
            'nik',
            'nip',
            'nuptk',
            'nama',
            'jenis_kelamin',
            'jenis_ptk_id_str',
            'jabatan_ptk_id_str',
            'status_kepegawaian_id_str',
            'pangkat_golongan_terakhir'
        ];

        if (!in_array($sort, $allowedSort)) {
            $sort = 'nama';
        }

        if (!in_array(
            strtolower($direction),
            ['asc', 'desc']
        )) {
            $direction = 'asc';
        }

        $teachers = $query
            ->orderBy($sort, $direction)
            ->get();

        $dataArray = [];

        /*
         * HEADER
         *
         * No. selalu berada paling kiri.
         */
        $dataArray[] = [
            'No.',
            'NIP',
            'NUPTK',
            'NIK',
            'Nama Lengkap',
            'L/P',
            'Tempat, Tgl Lahir',
            'Agama',
            'Jenis GTK',
            'Jabatan',
            'Status Kepegawaian',
            'Pendidikan Akhir',
            'Bidang Studi',
            'Pangkat/Gol',
            'PTK ID',
            'PTK Terdaftar ID',
            'Tahun Ajaran ID',
            'PTK Induk',
            'Tgl Surat Tugas',
            'Riwayat Pendidikan Formal',
            'Riwayat Kepangkatan'
        ];

        $nomor = 1;

        foreach ($teachers as $t) {

            /*
             * Nama lengkap
             */
            $namaLengkap = !empty($t->nama_lengkap)
                ? $t->nama_lengkap
                : $this->generateNamaLengkap(
                    $t->gelar_depan,
                    $t->nama,
                    $t->gelar_belakang
                );

            /*
             * Tempat dan tanggal lahir
             */
            $tempatTanggalLahir = '-';

            if (
                !empty($t->tempat_lahir) ||
                !empty($t->tanggal_lahir)
            ) {

                $tglLahir = !empty($t->tanggal_lahir)
                    ? Carbon::parse(
                        $t->tanggal_lahir
                    )->format('d/m/Y')
                    : '-';

                $tempat = $this->normalizeText(
                    $t->tempat_lahir
                );

                if ($tempat === null || $tempat === '') {
                    $tempat = '-';
                }

                $tempatTanggalLahir =
                    $tempat . ', ' . $tglLahir;
            }

            /*
             * Riwayat Pendidikan Formal
             */
            $rwyPendFormalText = '-';

            if (!empty($t->rwy_pend_formal)) {

                $rwyPendFormal = json_decode(
                    $t->rwy_pend_formal,
                    true
                );

                if (
                    is_array($rwyPendFormal) &&
                    count($rwyPendFormal) > 0
                ) {

                    $pendList = [];

                    foreach ($rwyPendFormal as $item) {

                        $jenjang =
                            $this->normalizeText(
                                $item[
                                    'jenjang_pendidikan_id_str'
                                ] ?? '-'
                            );

                        $satuan =
                            $this->normalizeText(
                                $item[
                                    'satuan_pendidikan_formal'
                                ] ?? '-'
                            );

                        $tahun =
                            $item['tahun_lulus'] ?? '-';

                        $pendList[] =
                            $jenjang .
                            ' - ' .
                            $satuan .
                            ' (' .
                            $tahun .
                            ')';
                    }

                    $rwyPendFormalText =
                        implode(
                            ' | ',
                            $pendList
                        );
                }
            }

            /*
             * Riwayat Kepangkatan
             */
            $rwyKepangkatanText = '-';

            if (!empty($t->rwy_kepangkatan)) {

                $rwyKepangkatan = json_decode(
                    $t->rwy_kepangkatan,
                    true
                );

                if (
                    is_array($rwyKepangkatan) &&
                    count($rwyKepangkatan) > 0
                ) {

                    $kpList = [];

                    foreach ($rwyKepangkatan as $item) {

                        $pangkat =
                            $item[
                                'pangkat_golongan_id_str'
                            ] ?? '-';

                        $tmt =
                            $item[
                                'tmt_pangkat'
                            ] ?? '-';

                        $kpList[] =
                            $pangkat .
                            ' (TMT: ' .
                            $tmt .
                            ')';
                    }

                    $rwyKepangkatanText =
                        implode(
                            ' | ',
                            $kpList
                        );
                }
            }

            /*
             * NIP, NUPTK dan NIK
             *
             * Sengaja diberi tanda apostrof di depan
             * agar Excel memperlakukannya sebagai TEXT.
             *
             * Nomor tetap tidak berubah.
             */
            $nip = !empty($t->nip)
                ? "'" . ($includeSensitive ? (string) $t->nip : \App\Support\SensitiveDataProtector::maskIdentifier($t->nip))
                : '-';

            $nuptk = !empty($t->nuptk)
                ? "'" . ($includeSensitive ? (string) $t->nuptk : \App\Support\SensitiveDataProtector::maskIdentifier($t->nuptk))
                : '-';

            $nik = !empty($t->nik)
                ? "'" . ($includeSensitive ? (string) $t->nik : \App\Support\SensitiveDataProtector::maskIdentifier($t->nik))
                : '-';

            /*
             * DATA
             */
            $dataArray[] = [

                // No.
                $nomor,

                // NIP - TEXT
                $nip,

                // NUPTK - TEXT
                $nuptk,

                // NIK - TEXT
                $nik,

                // Nama
                $namaLengkap,

                // L/P
                $t->jenis_kelamin ?? '-',

                // Tempat, Tgl Lahir
                $tempatTanggalLahir,

                // Agama
                $this->normalizeText(
                    $t->agama_id_str
                ) ?: '-',

                // Jenis GTK
                $this->normalizeText(
                    $t->jenis_ptk_id_str
                        ?? $t->jenis_ptk
                ) ?: '-',

                // Jabatan
                $this->normalizeText(
                    $t->jabatan_ptk_id_str
                        ?? $t->jabatan_ptk
                ) ?: '-',

                // Status Kepegawaian
                $this->normalizeText(
                    $t->status_kepegawaian_id_str
                        ?? $t->status_kepegawaian
                ) ?: '-',

                // Pendidikan Akhir
                $this->normalizeText(
                    $t->pendidikan_terakhir
                ) ?: '-',

                // Bidang Studi
                $this->normalizeText(
                    $t->bidang_studi_terakhir
                ) ?: '-',

                // Pangkat/Gol
                $this->formatPangkat(
                    $t->pangkat_golongan_terakhir
                ),

                // PTK ID
                $t->dapodik_ptk_id ?? '-',

                // PTK Terdaftar ID
                $t->ptk_terdaftar_id ?? '-',

                // Tahun Ajaran ID
                $t->tahun_ajaran_id ?? '-',

                // PTK Induk
                $t->ptk_induk ?? '-',

                // Tgl Surat Tugas
                $t->tanggal_surat_tugas ?? '-',

                // Riwayat Pendidikan Formal
                $rwyPendFormalText,

                // Riwayat Kepangkatan
                $rwyKepangkatanText,
            ];

            $nomor++;
        }

        /*
         * Export Excel
         *
         * Tidak menggunakan WithAutoFilter karena
         * interface tersebut tidak tersedia pada
         * versi Maatwebsite Excel yang digunakan.
         */
        return Excel::download(
            new class($dataArray)
                implements
                    FromArray,
                    WithColumnFormatting,
                    WithStyles,
                    WithEvents
            {
                private $data;

                public function __construct($data)
                {
                    $this->data = $data;
                }

                public function array(): array
                {
                    return $this->data;
                }

                /**
                 * Format kolom Excel.
                 *
                 * A = No.
                 * B = NIP
                 * C = NUPTK
                 * D = NIK
                 */
                public function columnFormats(): array
                {
                    return [
                        'A' => NumberFormat::FORMAT_NUMBER,
                        'B' => NumberFormat::FORMAT_TEXT,
                        'C' => NumberFormat::FORMAT_TEXT,
                        'D' => NumberFormat::FORMAT_TEXT,
                    ];
                }

                /**
                 * Styling worksheet.
                 */
                public function styles(
                    Worksheet $sheet
                ): array {

                    return [
                        /*
                         * Header
                         */
                        1 => [
                            'font' => [
                                'bold' => true,
                            ],
                            'alignment' => [
                                'horizontal' => 'center',
                                'vertical' => 'center',
                                'wrapText' => true,
                            ],
                        ],

                        /*
                         * Seluruh tabel
                         */
                        'A:U' => [
                            'alignment' => [
                                'vertical' => 'center',
                            ],
                        ],
                    ];
                }

                /**
                 * Event setelah sheet selesai dibuat.
                 */
                public function registerEvents(): array
                {
                    return [
                        AfterSheet::class => function (
                            AfterSheet $event
                        ) {

                            $sheet =
                                $event->sheet
                                    ->getDelegate();

                            $highestRow =
                                $sheet->getHighestRow();

                            $highestColumn =
                                $sheet->getHighestColumn();

                            /*
                             * Freeze header.
                             */
                            $sheet->freezePane('A2');

                            /*
                             * Auto Filter.
                             *
                             * Ini bukan WithAutoFilter,
                             * sehingga kompatibel dengan
                             * versi package yang digunakan.
                             */
                            if ($highestRow >= 1) {
                                $sheet->setAutoFilter(
                                    'A1:' .
                                    $highestColumn .
                                    $highestRow
                                );
                            }

                            /*
                             * Border seluruh tabel.
                             */
                            $sheet
                                ->getStyle(
                                    'A1:' .
                                    $highestColumn .
                                    $highestRow
                                )
                                ->getBorders()
                                ->getAllBorders()
                                ->setBorderStyle(
                                    \PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN
                                );

                            /*
                             * Header alignment.
                             */
                            $sheet
                                ->getStyle(
                                    'A1:' .
                                    $highestColumn .
                                    '1'
                                )
                                ->getAlignment()
                                ->setHorizontal(
                                    \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
                                )
                                ->setVertical(
                                    \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_CENTER
                                )
                                ->setWrapText(true);

                            /*
                             * Tinggi header.
                             */
                            $sheet
                                ->getRowDimension(1)
                                ->setRowHeight(32);

                            /*
                             * Kolom No.
                             */
                            $sheet
                                ->getColumnDimension('A')
                                ->setWidth(7);

                            /*
                             * NIP
                             */
                            $sheet
                                ->getColumnDimension('B')
                                ->setWidth(20);

                            /*
                             * NUPTK
                             */
                            $sheet
                                ->getColumnDimension('C')
                                ->setWidth(20);

                            /*
                             * NIK
                             */
                            $sheet
                                ->getColumnDimension('D')
                                ->setWidth(20);

                            /*
                             * Nama
                             */
                            $sheet
                                ->getColumnDimension('E')
                                ->setWidth(30);

                            /*
                             * L/P
                             */
                            $sheet
                                ->getColumnDimension('F')
                                ->setWidth(8);

                            /*
                             * Tempat, Tgl Lahir
                             */
                            $sheet
                                ->getColumnDimension('G')
                                ->setWidth(25);

                            /*
                             * Agama
                             */
                            $sheet
                                ->getColumnDimension('H')
                                ->setWidth(15);

                            /*
                             * Jenis GTK
                             */
                            $sheet
                                ->getColumnDimension('I')
                                ->setWidth(22);

                            /*
                             * Jabatan
                             */
                            $sheet
                                ->getColumnDimension('J')
                                ->setWidth(25);

                            /*
                             * Status Kepegawaian
                             */
                            $sheet
                                ->getColumnDimension('K')
                                ->setWidth(24);

                            /*
                             * Pendidikan
                             */
                            $sheet
                                ->getColumnDimension('L')
                                ->setWidth(22);

                            /*
                             * Bidang Studi
                             */
                            $sheet
                                ->getColumnDimension('M')
                                ->setWidth(25);

                            /*
                             * Pangkat/Gol
                             */
                            $sheet
                                ->getColumnDimension('N')
                                ->setWidth(30);

                            /*
                             * ID dan data lainnya
                             */
                            $sheet
                                ->getColumnDimension('O')
                                ->setWidth(18);

                            $sheet
                                ->getColumnDimension('P')
                                ->setWidth(22);

                            $sheet
                                ->getColumnDimension('Q')
                                ->setWidth(20);

                            $sheet
                                ->getColumnDimension('R')
                                ->setWidth(15);

                            $sheet
                                ->getColumnDimension('S')
                                ->setWidth(20);

                            /*
                             * Riwayat Pendidikan Formal
                             */
                            $sheet
                                ->getColumnDimension('T')
                                ->setWidth(45);

                            /*
                             * Riwayat Kepangkatan
                             */
                            $sheet
                                ->getColumnDimension('U')
                                ->setWidth(45);

                            /*
                             * Wrap text untuk seluruh
                             * data tabel.
                             */
                            if ($highestRow >= 2) {

                                $sheet
                                    ->getStyle(
                                        'A2:' .
                                        $highestColumn .
                                        $highestRow
                                    )
                                    ->getAlignment()
                                    ->setVertical(
                                        \PhpOffice\PhpSpreadsheet\Style\Alignment::VERTICAL_TOP
                                    );

                                /*
                                 * Wrap text untuk kolom
                                 * yang dapat berisi teks panjang.
                                 */
                                $sheet
                                    ->getStyle(
                                        'E2:U' .
                                        $highestRow
                                    )
                                    ->getAlignment()
                                    ->setWrapText(true);

                                /*
                                 * Nomor, NIP, NUPTK,
                                 * NIK dan L/P rata tengah.
                                 */
                                $sheet
                                    ->getStyle(
                                        'A2:D' .
                                        $highestRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
                                    );

                                $sheet
                                    ->getStyle(
                                        'F2:F' .
                                        $highestRow
                                    )
                                    ->getAlignment()
                                    ->setHorizontal(
                                        \PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER
                                    );
                            }

                            /*
                             * Pastikan kolom NIP,
                             * NUPTK dan NIK tetap
                             * menggunakan format TEXT.
                             */
                            if ($highestRow >= 2) {

                                $sheet
                                    ->getStyle(
                                        'B2:D' .
                                        $highestRow
                                    )
                                    ->getNumberFormat()
                                    ->setFormatCode(
                                        NumberFormat::FORMAT_TEXT
                                    );
                            }

                            /*
                             * Tinggi baris data.
                             */
                            for (
                                $row = 2;
                                $row <= $highestRow;
                                $row++
                            ) {
                                $sheet
                                    ->getRowDimension($row)
                                    ->setRowHeight(30);
                            }
                        },
                    ];
                }
            },
            'data_gtk_' .
            date('Y_m_d') .
            '.xlsx'
        );
    }
}
<?php

namespace App\Http\Controllers;

use App\Services\Sync\WorkProductPushService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

class BkController extends Controller
{
    /** [06.1] Versi epoch-ms bila kolom sync_version tersedia. */
    private ?bool $bkHasSyncVersion = null;

    /**
     * Helper untuk mendapatkan nama kelas reguler siswa via Subquery.
     * Jauh lebih cepat dan rapi daripada LEFT JOIN + GROUP BY.
     */
    private function getKelasSubquery()
    {
        return DB::table('enrollments')
            ->join('homerooms', 'enrollments.homeroom_id', '=', 'homerooms.id')
            ->whereColumn('enrollments.student_id', 'students.id')
            ->where('homerooms.jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('homerooms.jenis_rombel', 'not like', '%Pilihan%')
            ->select('homerooms.nama')
            ->limit(1);
    }

    /* ============================================================
     * [06.1/06.2] HELPERS SINKRON
     * ============================================================ */

    private function bkHasSyncVersion(): bool
    {
        if ($this->bkHasSyncVersion === null) {
            try {
                $this->bkHasSyncVersion = Schema::hasColumn('bk_logs', 'sync_version');
            } catch (Throwable) {
                $this->bkHasSyncVersion = false;
            }
        }

        return $this->bkHasSyncVersion;
    }

    /** [06.1] Versi epoch-ms - LWW lintas jalur (HP vs web vs batch). */
    private function stampBkVersion(): int
    {
        if (!$this->bkHasSyncVersion()) {
            return 1;
        }

        return (int) floor(microtime(true) * 1000);
    }

    /**
     * [06.3] teacher_id konteks login (fallback pencarian via
     * nip/nik/nuptk). 0 = tidak teridentifikasi.
     */
    private function resolveTeacherId(): int
    {
        $user = auth()->user();

        if (!$user) {
            return 0;
        }

        $teacherId = DB::table('teachers')->where('user_id', $user->id)->value('id');

        if ($teacherId) {
            return (int) $teacherId;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return 0;
        }

        return (int) (
            DB::table('teachers')
                ->where('nip', $loginId)
                ->orWhere('nik', $loginId)
                ->orWhere('nuptk', $loginId)
                ->value('id')
            ?? 0
        );
    }

    /**
     * Menampilkan halaman BK.
     */
    public function index(): View
    {
        $students = DB::table('students')
            ->orderBy('nama')
            ->get();

        // Menggunakan selectSub agar tidak perlu LEFT JOIN dan GROUP BY panjang
        $riwayats = DB::table('bk_logs')
            ->join('students', 'bk_logs.student_id', '=', 'students.id')
            ->select(
                'bk_logs.id',
                'bk_logs.student_id',
                'bk_logs.tanggal',
                'bk_logs.permasalahan',
                'bk_logs.penanganan',
                'bk_logs.bobot_kasus',
                'bk_logs.poin',
                'students.nama',
                'students.nisn'
            )
            ->selectSub($this->getKelasSubquery(), 'kelas')
            ->orderBy('bk_logs.tanggal', 'desc')
            ->get();

        return view('guru.bk', compact('students', 'riwayats'));
    }

    /**
     * Menyimpan kasus BK baru.
     *
     * [06.1] sync_version di-stamp (bila kolom tersedia) - LWW lintas
     * jalur hidup utk baris BK.
     * [06.2] Setelah tersimpan: markDirty(teacher_id) - daemon mendorong
     * batch bk_log_batch ke Cloud otomatis, fan-out ter-scope ke semua
     * device guru yang relevan (SCOPE-TA di Worker mencocokkan via
     * teacher_id dalam payload).
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'student_id' => 'required|exists:students,id',
            'tanggal' => 'required|date',
            'permasalahan' => 'required|string',
            'penanganan' => 'required|string',
            'bobot_kasus' => 'required|in:Ringan,Sedang,Berat',
            'poin' => 'required|integer|min:0',
        ]);

        $teacherId = $this->resolveTeacherId();
        $version = $this->stampBkVersion();

        $values = [
            'student_id' => (int) $request->student_id,
            'tanggal' => $request->tanggal,
            'permasalahan' => $request->permasalahan,
            'penanganan' => $request->penanganan,
            'bobot_kasus' => $request->bobot_kasus,
            'poin' => (int) $request->poin,
            'teacher_id' => $teacherId > 0 ? $teacherId : null,   // [SCOPE-TA]
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($this->bkHasSyncVersion()) {
            $values['sync_version'] = $version;
        }

        DB::table('bk_logs')->insert($values);

        /*
         * [06.2] BK berubah -> batch bk_log_batch terkirim otomatis
         * ke Cloud (fan-out ke semua device guru yang relevan).
         * markDirty tahan-gagal: kegagalan sinkron tidak menggagalkan
         * penyimpanan kasus.
         */
        if ($teacherId > 0) {
            WorkProductPushService::markDirty($teacherId);
        }

        return redirect()
            ->back()
            ->with('success', 'Riwayat kasus BK berhasil disimpan! Data akan tersinkron otomatis.');
    }

    /**
     * Menghapus satu riwayat kasus BK.
     *
     * [06.2] Owner (teacher_id) diambil SEBELUM delete - dulu dibaca
     * setelah baris hilang sehingga selalu null. Setelah delete:
     * markDirty agar penghapusan menyebar lewat replace-per-scope
     * batch berikutnya (kasus ikut terhapus di laptop lain).
     */
    public function destroy($id): RedirectResponse
    {
        $riwayat = DB::table('bk_logs')
            ->where('id', $id)
            ->first();

        if (!$riwayat) {
            return redirect()
                ->back()
                ->with('error', 'Riwayat kasus BK tidak ditemukan.');
        }

        $ownerId = (int) ($riwayat->teacher_id ?? 0);

        DB::table('bk_logs')
            ->where('id', $id)
            ->delete();

        if ($ownerId > 0) {
            WorkProductPushService::markDirty($ownerId);
        } else {
            // Row lama tanpa teacher_id (data warisan) - pakai konteks login.
            $loginTeacherId = $this->resolveTeacherId();

            if ($loginTeacherId > 0) {
                WorkProductPushService::markDirty($loginTeacherId);
            }
        }

        return redirect()
            ->back()
            ->with('success', 'Riwayat kasus BK berhasil dihapus.');
    }

    /**
     * Export rekap kasus BK ke Excel.
     *
     * Format konsisten dengan export Excel lainnya:
     * - No. selalu di kolom paling kiri
     * - Header bold rata tengah, border seluruh tabel
     * - Isi wrap text, lebar kolom disesuaikan
     * - NISN dipertahankan sebagai TEXT
     */
    public function export()
    {
        $riwayats = DB::table('bk_logs')
            ->join('students', 'bk_logs.student_id', '=', 'students.id')
            ->select(
                'bk_logs.tanggal',
                'students.nisn',
                'students.nama',
                'bk_logs.permasalahan',
                'bk_logs.penanganan',
                'bk_logs.bobot_kasus',
                'bk_logs.poin'
            )
            ->selectSub($this->getKelasSubquery(), 'kelas')
            ->orderBy('bk_logs.tanggal', 'desc')
            ->get();

        $dataArray = [];

        foreach ($riwayats as $index => $r) {

            /*
             * NISN dipaksa menjadi string.
             * Prefix apostrof menjaga Excel agar memperlakukan
             * NISN sebagai TEXT sehingga angka 0 di depan tidak hilang.
             */
            $nisn = !empty($r->nisn)
                ? "'" . (string) $r->nisn
                : '-';

            $dataArray[] = [
                $index + 1,
                $r->tanggal ?? '-',
                $nisn,
                $r->nama ?? '-',
                $r->kelas ?? '-',
                $r->permasalahan ?? '-',
                $r->penanganan ?? '-',
                $r->bobot_kasus ?? '-',
                $r->poin ?? 0,
            ];
        }

        /*
         * Header Excel.
         *
         * No. SELALU berada di kolom paling kiri.
         */
        $headers = [
            'No.',
            'Tanggal',
            'NISN',
            'Nama Murid',
            'Kelas',
            'Permasalahan',
            'Penanganan',
            'Bobot Kasus',
            'Poin',
        ];

        /*
         * Export class.
         *
         * Tidak menggunakan WithAutoFilter karena versi
         * Laravel Excel yang digunakan tidak menyediakan
         * interface tersebut.
         */
        $exportClass = new class($dataArray, $headers)
            implements FromArray, WithHeadings, WithStyles, WithColumnWidths, WithColumnFormatting
        {
            private $data;
            private $headers;

            public function __construct($data, $headers)
            {
                $this->data = $data;
                $this->headers = $headers;
            }

            /**
             * Data tabel.
             */
            public function array(): array
            {
                return $this->data;
            }

            /**
             * Header tabel.
             */
            public function headings(): array
            {
                return $this->headers;
            }

            /**
             * Format kolom.
             *
             * NISN berada di kolom C.
             */
            public function columnFormats(): array
            {
                return [
                    'C' => NumberFormat::FORMAT_TEXT,
                ];
            }

            /**
             * Lebar masing-masing kolom.
             */
            public function columnWidths(): array
            {
                return [
                    'A' => 7,   // No.
                    'B' => 14,  // Tanggal
                    'C' => 20,  // NISN
                    'D' => 28,  // Nama Siswa
                    'E' => 15,  // Kelas
                    'F' => 35,  // Permasalahan
                    'G' => 35,  // Penanganan
                    'H' => 18,  // Bobot Kasus
                    'I' => 10,  // Poin
                ];
            }

            /**
             * Styling seluruh worksheet.
             */
            public function styles(Worksheet $sheet)
            {
                /*
                 * Tentukan baris terakhir.
                 *
                 * +1 karena baris pertama adalah header.
                 */
                $lastRow = count($this->data) + 1;

                /*
                 * Header.
                 */
                $sheet->getStyle('A1:I1')->applyFromArray([
                    'font' => [
                        'bold' => true,
                        'size' => 11,
                    ],
                    'alignment' => [
                        'horizontal' => 'center',
                        'vertical' => 'center',
                        'wrapText' => true,
                    ],
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => 'thin',
                        ],
                    ],
                ]);

                /*
                 * Tinggi header.
                 */
                $sheet->getRowDimension(1)->setRowHeight(30);

                /*
                 * Jika terdapat data.
                 */
                if ($lastRow >= 2) {

                    /*
                     * Border seluruh tabel.
                     */
                    $sheet->getStyle("A1:I{$lastRow}")
                        ->getBorders()
                        ->getAllBorders()
                        ->setBorderStyle('thin');

                    /*
                     * Isi tabel.
                     */
                    $sheet->getStyle("A2:I{$lastRow}")
                        ->getAlignment()
                        ->setVertical('top');

                    /*
                     * Wrap text untuk isi.
                     */
                    $sheet->getStyle("A2:I{$lastRow}")
                        ->getAlignment()
                        ->setWrapText(true);

                    /*
                     * Kolom No., Tanggal, NISN,
                     * Bobot Kasus, dan Poin dibuat rata tengah.
                     */
                    $sheet->getStyle("A2:C{$lastRow}")
                        ->getAlignment()
                        ->setHorizontal('center');

                    $sheet->getStyle("H2:I{$lastRow}")
                        ->getAlignment()
                        ->setHorizontal('center');

                    /*
                     * Tinggi baris otomatis.
                     */
                    for ($row = 2; $row <= $lastRow; $row++) {
                        $sheet->getRowDimension($row)->setRowHeight(-1);
                    }
                }

                /*
                 * Freeze header.
                 *
                 * Saat scroll ke bawah,
                 * header tetap terlihat.
                 */
                $sheet->freezePane('A2');

                /*
                 * Posisi teks default.
                 */
                $sheet->getStyle("A1:I{$lastRow}")
                    ->getAlignment()
                    ->setVertical('center');

                return [];
            }
        };

        return Excel::download(
            $exportClass,
            'Rekap_Kasus_BK_' . date('Y_m_d') . '.xlsx'
        );
    }
}
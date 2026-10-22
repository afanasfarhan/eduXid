<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class SuratController extends Controller
{
    /**
     * ============================================================
     * HALAMAN UTAMA MODUL SURAT
     * ============================================================
     */
    public function index()
    {
        return $this->buat();
    }

    /**
     * ============================================================
     * HALAMAN FORM PEMBUATAN SURAT
     * ============================================================
     */
    public function buat()
    {
        $school = DB::table('schools')->first();

        $namaSekolah = isset($school->nama) ? $school->nama : 'Sekolah';
        $tempatSuratDefault = isset($school->kecamatan) ? $school->kecamatan : 'Tempat';

        $kopSuratPermanen = DB::table('app_settings')
            ->where('key', 'kop_surat')
            ->value('value');

        $teachers = DB::table('teachers')
            ->orderBy('nama')
            ->get()
            ->map(function ($t) {
                $gelarDepan = trim(isset($t->gelar_depan) ? $t->gelar_depan : '');
                $nama = trim(isset($t->nama) ? $t->nama : '');
                $gelarBelakang = trim(isset($t->gelar_belakang) ? $t->gelar_belakang : '');

                $namaLengkap = '';
                if ($gelarDepan !== '') {
                    $namaLengkap .= $gelarDepan . ' ';
                }
                $namaLengkap .= $nama;
                if ($gelarBelakang !== '') {
                    $namaLengkap .= ', ' . $gelarBelakang;
                }

                $pangkatGolongan = trim(isset($t->pangkat_golongan_terakhir) ? $t->pangkat_golongan_terakhir : '');
                $pangkatGolonganNormal = preg_replace('/\s+/', ' ', $pangkatGolongan);
                $pangkatGolonganNormal = trim($pangkatGolonganNormal);

                $daftarPangkat = [
                    'I/a'   => 'Juru Muda', 'I/b'   => 'Juru Muda Tingkat I',
                    'I/c'   => 'Juru', 'I/d'   => 'Juru Tingkat I',
                    'II/a'  => 'Pengatur Muda', 'II/b'  => 'Pengatur Muda Tingkat I',
                    'II/c'  => 'Pengatur', 'II/d'  => 'Pengatur Tingkat I',
                    'III/a' => 'Penata Muda', 'III/b' => 'Penata Muda Tingkat I',
                    'III/c' => 'Penata', 'III/d' => 'Penata Tingkat I',
                    'IV/a'  => 'Pembina', 'IV/b'  => 'Pembina Tingkat I',
                    'IV/c'  => 'Pembina Utama Muda', 'IV/d'  => 'Pembina Utama Madya',
                    'IV/e'  => 'Pembina Utama',
                ];

                $daftarPangkatSingkat = [
                    'I/a'   => 'Juru Muda', 'I/b'   => 'Juru Muda Tk. I',
                    'I/c'   => 'Juru', 'I/d'   => 'Juru Tk. I',
                    'II/a'  => 'Pengatur Muda', 'II/b'  => 'Pengatur Muda Tk. I',
                    'II/c'  => 'Pengatur', 'II/d'  => 'Pengatur Tk. I',
                    'III/a' => 'Penata Muda', 'III/b' => 'Penata Muda Tk. I',
                    'III/c' => 'Penata', 'III/d' => 'Penata Tk. I',
                    'IV/a'  => 'Pembina', 'IV/b'  => 'Pembina Tk. I',
                    'IV/c'  => 'Pembina Utama Muda', 'IV/d'  => 'Pembina Utama Madya',
                    'IV/e'  => 'Pembina Utama',
                ];

                $kodeGolongan = '';
                foreach ($daftarPangkat as $kode => $namaPangkat) {
                    if (strcasecmp($pangkatGolonganNormal, $kode) === 0) {
                        $kodeGolongan = $kode;
                        break;
                    }
                }

                if ($kodeGolongan !== '') {
                    $pangkatLengkap = $daftarPangkatSingkat[$kodeGolongan] . ', ' . $kodeGolongan;
                } else {
                    $pangkatLengkap = $pangkatGolonganNormal;
                    if ($pangkatLengkap === '') {
                        $pangkatLengkap = '-';
                    }
                }

                $t->nama_lengkap = $namaLengkap;
                $t->pangkat_lengkap = $pangkatLengkap;

                return $t;
            });

        $kepseks = $teachers->filter(function ($t) {
            $jabatan = isset($t->jabatan_ptk) ? $t->jabatan_ptk : '';
            $jenis = isset($t->jenis_ptk) ? $t->jenis_ptk : '';
            return stripos($jabatan, 'Kepala Sekolah') !== false || stripos($jenis, 'Kepala Sekolah') !== false;
        })->values();

        $guruBks = $teachers
            ->filter(function ($t) {
                $jabatan = strtolower(trim((string)($t->jabatan_ptk ?? '')));
                $jenis = strtolower(trim((string)($t->jenis_ptk ?? '')));
                
                // Cek berbagai kemungkinan penulisan dari Dapodik
                $isBk = stripos($jenis, 'BK') !== false || stripos($jabatan, 'BK') !== false;
                $isKonselor = stripos($jenis, 'Konselor') !== false || stripos($jabatan, 'Konselor') !== false;
                $isBimbingan = stripos($jenis, 'Bimbingan Konseling') !== false || stripos($jabatan, 'Bimbingan Konseling') !== false;
                
                return $isBk || $isKonselor || $isBimbingan;
            })
            ->values();

        $students = DB::table('students')
            ->select('students.*', 'homerooms.id as homeroom_id', 'homerooms.nama as kelas_rombel', 'homerooms.homeroom_teacher_id as wali_kelas_id')
            ->join('enrollments', function ($join) {
                $join->on('enrollments.student_id', '=', 'students.id');
            })
            ->join('homerooms', function ($join) {
                $join->on('homerooms.id', '=', 'enrollments.homeroom_id');
            })
            ->where('homerooms.jenis_rombel', 'Kelas')
            ->orderBy('students.nama')
            ->get();

        $specialLetterTypes = [
            'Keterangan',
            'Tugas',
            'Pernyataan',
            'Rekomendasi',
            'Dispensasi',
        ];

        return view('tu.surat.index', compact(
            'teachers', 'kepseks', 'guruBks', 'students', 'namaSekolah', 'tempatSuratDefault', 'specialLetterTypes', 'kopSuratPermanen'
        ));
    }

    /**
     * ============================================================
     * PROSES SIMPAN SURAT / CETAK
     * ============================================================
     */
    public function cetak(Request $request)
    {
        $validated = $request->validate([
            'kop_surat' => 'nullable|file|mimes:png,jpg,jpeg,webp|max:2048',
            'jenis_surat' => 'required|string',
            'nomor_surat' => 'required|string',
            'tanggal_surat' => 'required|date',
            'tempat_surat' => 'nullable|string',
            'lampiran' => 'nullable|string',
            'perihal' => 'required|string',
            'tujuan' => 'nullable|string',
            'alamat_tujuan' => 'nullable|string',
            'isi_surat' => 'required|string',
            'penandatangan_jabatan' => 'required|string',
            'penandatangan_id' => 'nullable|string',
            'nama_penanda_tangan' => 'required|string',
            'nip_penanda_tangan' => 'nullable|string',
            'pangkat_golongan' => 'nullable|string',
            'nama_siswa' => 'nullable|string',
            'nis' => 'nullable|string',
            'nisn' => 'nullable|string',
            'nipd' => 'nullable|string',
            'kelas' => 'nullable|string',
            'hari_kegiatan' => 'nullable|string',
            'tanggal_kegiatan' => 'nullable|string',
            'waktu_mulai' => 'nullable|string',
            'waktu_selesai' => 'nullable|string',
            'tempat' => 'nullable|string',
            'acara' => 'nullable|string',
        ]);

        $kopSuratPath = DB::table('app_settings')->where('key', 'kop_surat')->value('value');

        if ($request->hasFile('kop_surat')) {
            $file = $request->file('kop_surat');
            $filename = 'kop_surat_' . time() . '.' . $file->getClientOriginalExtension();
            $path = app(\App\Services\LocalMediaService::class)->storeUploadedAs($file, 'kop_surat', $filename);

            DB::table('app_settings')->updateOrInsert(
                ['key' => 'kop_surat'],
                ['value' => $path, 'updated_at' => now(), 'created_at' => now()]
            );

            $kopSuratPath = $path;
        }

        $school = DB::table('schools')->first();
        $data = $validated;
        $data['kop_surat'] = $kopSuratPath;
        $data['school'] = (array) $school;

        try {
            $tanggalSurat = Carbon::parse($validated['tanggal_surat']);
            $data['tanggal_surat_indonesia'] = $tanggalSurat->locale('id')->translatedFormat('j F Y');
        } catch (\Throwable $e) {
            $data['tanggal_surat_indonesia'] = $validated['tanggal_surat'];
        }

        $recordId = DB::table('surat_records')->insertGetId([
            'jenis_surat' => $validated['jenis_surat'],
            'nomor_surat' => $validated['nomor_surat'],
            'tanggal_surat' => $validated['tanggal_surat'],
            'perihal' => $validated['perihal'],
            'isi_surat' => $validated['isi_surat'],
            'data_surat' => json_encode($data),
            'user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->route('tu.surat.cetak.preview', $recordId);
    }

    /**
     * ============================================================
     * PREVIEW / CETAK SURAT
     * ============================================================
     */
    public function cetakPreview($id)
    {
        $record = DB::table('surat_records')->where('id', $id)->first();
        if (!$record) { abort(404); }

        $school = DB::table('schools')->first();
        $kopSuratPath = DB::table('app_settings')->where('key', 'kop_surat')->value('value');

        $data = json_decode($record->data_surat, true);
        $data['kop_surat'] = $kopSuratPath;
        $data['school'] = (array) $school;

        try {
            $data['tanggal_surat_indonesia'] = Carbon::parse($record->tanggal_surat)->locale('id')->translatedFormat('j F Y');
        } catch (\Throwable $e) {
            $data['tanggal_surat_indonesia'] = $record->tanggal_surat;
        }

        return view('tu.surat.cetak', compact('data'));
    }

    /**
     * ============================================================
     * RIWAYAT SURAT KELUAR & MASUK
     * ============================================================
     */
    public function riwayat()
    {
        $riwayats = DB::table('surat_records')
            ->where('user_id', auth()->id())
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        // Ambil data surat masuk
        $suratMasuk = DB::table('surat_masuk')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return view('tu.surat.riwayat', compact('riwayats', 'suratMasuk'));
    }

    /**
     * ============================================================
     * SIMPAN SURAT MASUK
     * ============================================================
     */
    public function storeMasuk(Request $request)
    {
        $validated = $request->validate([
            'jenis_surat' => 'required|string|max:255',
            'nomor_surat' => 'required|string|max:255',
            'perihal' => 'required|string|max:255',
            'tanggal_surat' => 'required|date',
            'pengirim' => 'nullable|string|max:255', // TAMBAHKAN INI
            'file_surat' => 'nullable|file|mimes:pdf|max:5120',
        ]);

        $filePath = null;
        if ($request->hasFile('file_surat')) {
            $file = $request->file('file_surat');
            $fileName = 'surat_masuk_' . time() . '.' . $file->getClientOriginalExtension();
            $filePath = app(\App\Services\LocalMediaService::class)->storeUploadedAs($file, 'surat_masuk', $fileName);
        }

        DB::table('surat_masuk')->insert([
            'jenis_surat' => $validated['jenis_surat'],
            'nomor_surat' => $validated['nomor_surat'],
            'perihal' => $validated['perihal'],
            'tanggal_surat' => $validated['tanggal_surat'],
            'pengirim' => $validated['pengirim'], // TAMBAHKAN INI
            'file_path' => $filePath,
            'user_id' => auth()->id(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return redirect()->back()->with('success', 'Surat masuk berhasil diarsipkan.');
    }

    /**
     * ============================================================
     * HAPUS SURAT MASUK
     * ============================================================
     */
    public function destroyMasuk($id)
    {
        $surat = DB::table('surat_masuk')->where('id', $id)->first();

        if ($surat && !empty($surat->file_path)) {
            // Hapus file PDF dari storage jika ada
            app(\App\Services\LocalMediaService::class)->delete($surat->file_path);
        }

        DB::table('surat_masuk')->where('id', $id)->delete();

        return back()->with('success', 'Arsip surat masuk berhasil dihapus.');
    }

    /**
     * ============================================================
     * UPLOAD KOP SURAT PERMANEN
     * ============================================================
     */
    public function uploadKop(Request $request)
    {
        $request->validate([
            'kop_surat' => 'required|file|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        if ($request->hasFile('kop_surat')) {
            $file = $request->file('kop_surat');
            $filename = 'kop_surat_' . time() . '.' . $file->getClientOriginalExtension();
            $path = app(\App\Services\LocalMediaService::class)->storeUploadedAs($file, 'kop_surat', $filename);

            DB::table('app_settings')->updateOrInsert(
                ['key' => 'kop_surat'],
                ['value' => $path, 'updated_at' => now(), 'created_at' => now()]
            );

            return redirect()->back()->with('success', 'Kop surat permanen berhasil diperbarui.');
        }

        return redirect()->back()->with('error', 'Gagal mengunggah kop surat.');
    }

    /**
     * ============================================================
     * HAPUS RIWAYAT SURAT KELUAR
     * ============================================================
     */
    public function destroy($id)
    {
        DB::table('surat_records')->where('id', $id)->delete();
        return back()->with('success', 'Arsip surat berhasil dihapus.');
    }
}
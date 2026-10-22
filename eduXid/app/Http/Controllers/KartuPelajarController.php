<?php

namespace App\Http\Controllers;

use App\Models\KartuPelajarSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class KartuPelajarController extends Controller
{
    /**
     * ============================================================
     * HALAMAN KARTU PELAJAR
     * ============================================================
     */
    public function index()
    {
        /*
         * ========================================================
         * DATA PESERTA DIDIK
         * ========================================================
         */
        $students = DB::table('students')
            ->select(
                'students.*',
                'homerooms.id as homeroom_id',
                'homerooms.nama as kelas_rombel'
            )
            ->join(
                'enrollments',
                'enrollments.student_id',
                '=',
                'students.id'
            )
            ->join(
                'homerooms',
                'homerooms.id',
                '=',
                'enrollments.homeroom_id'
            )
            ->where(
                'homerooms.jenis_rombel',
                'Kelas'
            )
            ->orderBy(
                'students.nama'
            )
            ->get();


        /*
         * ========================================================
         * DATA ROMBEL / KELAS
         * ========================================================
         */
        $homerooms = DB::table('homerooms')
            ->select(
                'id',
                'nama'
            )
            ->where(
                'jenis_rombel',
                'Kelas'
            )
            ->orderBy(
                'nama'
            )
            ->get();


        /*
         * ========================================================
         * DATA SEKOLAH
         * ========================================================
         */
        $school = DB::table('schools')->first();


        /*
         * ========================================================
         * DATA KOP DAN TANDA TANGAN
         * ========================================================
         */
        $kopSuratPath = DB::table('app_settings')
            ->where(
                'key',
                'kop_surat'
            )
            ->value('value');


        $tandaTanganPath = DB::table('app_settings')
            ->where(
                'key',
                'tanda_tangan_kartu'
            )
            ->value('value');


        /*
         * ========================================================
         * DATA KEPALA SEKOLAH
         * ========================================================
         */
        $kepalaSekolah = null;

        $hasCondition = false;


        if (Schema::hasTable('teachers')) {

            $columns = Schema::getColumnListing(
                'teachers'
            );

            $query = DB::table('teachers');


            if (
                in_array(
                    'jabatan',
                    $columns
                )
            ) {

                $query->where(
                    'jabatan',
                    'like',
                    '%Kepala Sekolah%'
                );

                $hasCondition = true;
            }


            if (
                in_array(
                    'jenis_ptk',
                    $columns
                )
            ) {

                $query->orWhere(
                    'jenis_ptk',
                    'like',
                    '%Kepala Sekolah%'
                );

                $hasCondition = true;
            }


            if (
                in_array(
                    'tugas_tambahan',
                    $columns
                )
            ) {

                $query->orWhere(
                    'tugas_tambahan',
                    'like',
                    '%Kepala Sekolah%'
                );

                $hasCondition = true;
            }


            if (
                in_array(
                    'role',
                    $columns
                )
            ) {

                $query->orWhere(
                    'role',
                    'kepala_sekolah'
                );

                $hasCondition = true;
            }


            if ($hasCondition) {

                $kepalaSekolah = $query->first();


                if ($kepalaSekolah) {

                    $gelarDepan = trim(
                        isset($kepalaSekolah->gelar_depan)
                            ? $kepalaSekolah->gelar_depan
                            : ''
                    );


                    $nama = trim(
                        isset($kepalaSekolah->nama)
                            ? $kepalaSekolah->nama
                            : ''
                    );


                    $gelarBelakang = trim(
                        isset($kepalaSekolah->gelar_belakang)
                            ? $kepalaSekolah->gelar_belakang
                            : ''
                    );


                    $namaLengkap = '';


                    if (
                        $gelarDepan !== ''
                    ) {

                        $namaLengkap .=
                            $gelarDepan . ' ';
                    }


                    $namaLengkap .= $nama;


                    if (
                        $gelarBelakang !== ''
                    ) {

                        $namaLengkap .=
                            ', ' . $gelarBelakang;
                    }


                    $kepalaSekolah->nama_lengkap =
                        $namaLengkap;
                }
            }
        }


        /*
         * ========================================================
         * FALLBACK KEPALA SEKOLAH DARI USERS
         * ========================================================
         */
        if (!$kepalaSekolah) {

            $kepalaSekolah = DB::table('users')
                ->where(
                    'role',
                    'kepala_sekolah'
                )
                ->select(
                    'name as nama',
                    'nip as nip'
                )
                ->first();
        }


        /*
         * ========================================================
         * PENGATURAN KARTU PELAJAR
         * ========================================================
         */
        $media = app(\App\Services\LocalMediaService::class);

        $students->transform(function ($student) use ($media) {
            $student->foto_url = $media->url($student->foto_path ?? null);
            return $student;
        });

        $kopSuratUrl = $media->url($kopSuratPath);
        $tandaTanganUrl = $media->url($tandaTanganPath);

        $setting = KartuPelajarSetting::first();


        /*
         * ========================================================
         * IDENTITAS SEKOLAH
         * ========================================================
         */
        $schoolData = [

            'nama' =>
                $school?->nama ?? '',

            'npsn' =>
                $school?->npsn ?? '',

            'alamat_jalan' =>
                $school?->alamat_jalan ?? '',

            'kabupaten_kota' =>
                $school?->kabupaten_kota ?? '',

            'provinsi' =>
                $school?->provinsi ?? '',

            'kecamatan' =>
                $school?->kecamatan ?? '',

            'kode_pos' =>
                $school?->kode_pos ?? '',

            'email' =>
                $school?->email ?? '',

            'website' =>
                $school?->website ?? '',
        ];


        /*
         * ========================================================
         * DATA PENANDA TANGAN
         * ========================================================
         */
        $signatureData = [

            'jabatan' =>
                $setting?->jabatan_penanda_tangan
                ?? 'Kepala Sekolah',

            'nama' =>
                $setting?->nama_penanda_tangan
                ??
                (
                    isset(
                        $kepalaSekolah->nama_lengkap
                    )
                    &&
                    $kepalaSekolah->nama_lengkap !== ''
                        ? $kepalaSekolah->nama_lengkap
                        :
                        (
                            isset(
                                $kepalaSekolah->nama
                            )
                                ? $kepalaSekolah->nama
                                :
                                (
                                    isset(
                                        $kepalaSekolah->name
                                    )
                                        ? $kepalaSekolah->name
                                        : ''
                                )
                        )
                ),

            'nip' =>
                $setting?->nip_penanda_tangan
                ??
                (
                    isset(
                        $kepalaSekolah->nip
                    )
                        ? $kepalaSekolah->nip
                        : ''
                ),

            'tempat' =>
                $setting?->tempat_penanda_tangan
                ??
                (
                    $school?->kabupaten_kota
                    ?? ''
                ),

            'tanggal' =>
                $setting?->tanggal_penanda_tangan
                ?? now(),
        ];


        /*
         * ========================================================
         * TAMPILKAN HALAMAN
         * ========================================================
         */
        return view(
            'tu.kartu-pelajar.index',
            compact(
                'students',
                'homerooms',
                'schoolData',
                'setting',
                'signatureData',
                'kepalaSekolah',
                'kopSuratPath',
                'tandaTanganPath',
                'kopSuratUrl',
                'tandaTanganUrl'
            )
        );
    }


    /**
     * ============================================================
     * SIMPAN PENGATURAN KARTU PELAJAR
     * ============================================================
     */
    public function saveSettings(
        Request $request
    ) {

        $validated = $request->validate([

            'jabatan_penanda_tangan' =>
                'required|string|max:255',

            'nama_penanda_tangan' =>
                'required|string|max:255',

            'nip_penanda_tangan' =>
                'nullable|string|max:100',

            'tempat_penanda_tangan' =>
                'required|string|max:255',

            'tanggal_penanda_tangan' =>
                'required|date',
        ]);


        $setting =
            KartuPelajarSetting::first();


        if (!$setting) {

            $setting =
                new KartuPelajarSetting();
        }


        $setting->fill(
            $validated
        );

        $setting->save();


        return back()->with(
            'success',
            'Pengaturan penanda tangan kartu pelajar berhasil disimpan.'
        );
    }


    /**
     * ============================================================
     * UPLOAD KOP KARTU PELAJAR
     * ============================================================
     */
    public function uploadKop(Request $request)
    {
        $request->validate([
            'kop_kartu' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $file = $request->file('kop_kartu');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = 'kop_kartu_' . now()->format('Ymd_His') . '_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '.' . $extension;
        $path = app(\App\Services\LocalMediaService::class)->storeUploadedAs($file, 'kop_surat', $filename);

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'kop_surat'],
            ['value' => $path, 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', 'Kop kartu pelajar berhasil disimpan melalui Local Media.');
    }


    /**
     * ============================================================
     * UPLOAD GAMBAR TANDA TANGAN
     * ============================================================
     */
    public function uploadTandaTangan(Request $request)
    {
        $request->validate([
            'tanda_tangan' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048',
        ]);

        $file = $request->file('tanda_tangan');
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = 'ttd_kepsek_' . now()->format('Ymd_His') . '_' . \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6)) . '.' . $extension;
        $path = app(\App\Services\LocalMediaService::class)->storeUploadedAs($file, 'tanda_tangan', $filename);

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'tanda_tangan_kartu'],
            ['value' => $path, 'updated_at' => now(), 'created_at' => now()]
        );

        return back()->with('success', 'Gambar tanda tangan berhasil disimpan melalui Local Media.');
    }


    /**
     * ============================================================
     * MENAMPILKAN GAMBAR TANDA TANGAN
     *
     * File tidak dipanggil langsung melalui /storage.
     * Laravel membaca file dari storage/app/public/tanda_tangan
     * kemudian mengirimkannya langsung ke browser.
     * ============================================================
     */
    public function tandaTangan()
    {
        $path = DB::table('app_settings')->where('key', 'tanda_tangan_kartu')->value('value');
        return app(\App\Services\LocalMediaService::class)->response($path);
    }
}
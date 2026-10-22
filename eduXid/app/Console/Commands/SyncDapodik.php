<?php

namespace App\Console\Commands;

use App\Services\Sync\MasterRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SyncDapodik extends Command
{
    protected $signature = 'dapodik:sync {--path= : Folder sumber JSON Dapodik}';

    protected $description = 'Sinkronisasi data Dapodik ke database eduXid tanpa menghapus data manual Admin';

    private string $dataPath;

    public function __construct()
    {
        parent::__construct();
        $this->dataPath = storage_path('app/dapodik_temp');
    }

    private function normalizeText($text)
    {
        if (empty($text)) return $text;

        $text = mb_convert_case(trim($text), MB_CASE_TITLE, 'UTF-8');

        $abbreviations = [
            'Sman', 'Smpn', 'Mtsn', 'Mln', 'Sdn', 'Min', 'Tk',
            'Pns', 'Pppk', 'Gtt', 'Gty',
            'Sd', 'Smp', 'Sma', 'Smk', 'Bk', 'Mts', 'Mi', 'Ma',
            'Slb', 'Sltp', 'Slta'
        ];

        foreach ($abbreviations as $abbr) {
            $text = preg_replace('/\b' . preg_quote($abbr, '/') . '\b/', strtoupper($abbr), $text);
        }

        return $text;
    }

    public function handle(): int
    {
        $optionPath = trim((string) $this->option('path'));

        if ($optionPath !== '') {
            $this->dataPath =
                rtrim(
                    str_replace('\\', '/', $optionPath),
                    '/'
                ) . '/';
        }

        $this->newLine();
        $this->info('================================================');
        $this->info('  MEMULAI SINKRONISASI DAPODIK KE EDUXID');
        $this->info('================================================');
        $this->newLine();

        if (!File::isDirectory($this->dataPath)) {
            $this->error('Folder dapodik_temp tidak ditemukan: ' . $this->dataPath);
            $this->warn('Pastikan proses pengambilan data Dapodik melalui Web Service telah berhasil terlebih dahulu.');
            return self::FAILURE;
        }

        $jsonFiles = File::glob($this->dataPath . DIRECTORY_SEPARATOR . '*.json');

        if (empty($jsonFiles)) {
            $this->error('Tidak ditemukan file JSON Dapodik di folder dapodik_temp.');
            return self::FAILURE;
        }

        try {
            DB::transaction(function () {
                $this->syncSekolah();
                $this->syncGuru();
                $this->syncSiswa();
                $this->syncRombel();
                $this->generateUserAccounts();
            });

            /*
             * [REWRITE-03] Tandai push master+akun ke Cloud —
             * daemon akan mengirim otomatis (throttle 5 menit).
             * Gagal penanda tidak boleh menggagalkan sinkronisasi.
             */
            try {
                MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
                $this->info('  Push master & akun ke Cloud DITANDAI — daemon akan mengirim otomatis.');
            } catch (\Throwable) {
                // Diabaikan — sinkronisasi Dapodik tetap dianggap sukses.
            }

            $this->newLine();
            $this->info('================================================');
            $this->info('  SINKRONISASI DAPODIK BERHASIL');
            $this->info('================================================');
            $this->newLine();
            $this->line('Data yang tidak ada di Dapodik TIDAK dihapus.');
            $this->line('Data manual Admin tetap dipertahankan.');
            return self::SUCCESS;

        } catch (\Throwable $e) {
            $this->newLine();
            $this->error('================================================');
            $this->error('  SINKRONISASI DAPODIK GAGAL');
            $this->error('================================================');
            $this->error($e->getMessage());
            report($e);
            return self::FAILURE;
        }
    }

    private function syncSekolah(): void
    {
        $this->info('Menyinkronkan data sekolah...');
        $file = $this->dataPath . DIRECTORY_SEPARATOR . 'sekolah.json';

        /*
         * [REWRITE-01] GAGAL KERAS — dulu "dilewati" sehingga
         * sinkronisasi bisa sukses TANPA data sekolah; identitas
         * seluruh sistem lalu menunggu sia-sia.
         */
        if (!File::exists($file)) {
            throw new \Exception(
                'sekolah.json tidak ditemukan di dapodik_temp — '
                . 'penarikan data sekolah dari Dapodik gagal. '
                . 'Sinkronisasi dibatalkan; perbaiki penarikan lalu ulangi.'
            );
        }

        $data = $this->readJson($file);

        if (empty($data)) {
            throw new \Exception(
                'Data sekolah kosong pada sekolah.json — sinkronisasi dibatalkan.'
            );
        }

        $sekolah = isset($data[0]) && is_array($data[0]) ? $data[0] : $data;
        $sekolahId = $sekolah['sekolah_id'] ?? null;

        if (!$sekolahId) {
            throw new \Exception('sekolah_id tidak ditemukan pada sekolah.json.');
        }

        $schoolData = [
            'nama' => $this->normalizeText($sekolah['nama'] ?? null),
            'npsn' => $sekolah['npsn'] ?? null,
            'bentuk_pendidikan' => $this->normalizeText($sekolah['bentuk_pendidikan_id_str'] ?? null),
            'status_sekolah' => $this->normalizeText($sekolah['status_sekolah_str'] ?? null),
            'alamat_jalan' => $this->normalizeText($sekolah['alamat_jalan'] ?? null),
            'kode_pos' => $sekolah['kode_pos'] ?? null,
            'nomor_telepon' => $sekolah['nomor_telepon'] ?? null,
            'email' => $sekolah['email'] ?? null,
            'website' => $sekolah['website'] ?? null,
            'kecamatan' => $this->normalizeText($sekolah['kecamatan'] ?? null),
            'kabupaten_kota' => $this->normalizeText($sekolah['kabupaten_kota'] ?? null),
            'provinsi' => $this->normalizeText($sekolah['provinsi'] ?? null),
            'updated_at' => now(),
        ];

        /*
         * Identitas tambahan yang memang berasal dari Dapodik.
         * Ditambahkan secara schema-tolerant agar command tetap aman
         * ketika dijalankan sebelum migration terbaru diterapkan.
         */
        if ($this->columnExists('schools', 'nss')) {
            $schoolData['nss'] = $this->normalizeText($sekolah['nss'] ?? null);
        }

        if ($this->columnExists('schools', 'desa_kelurahan')) {
            $schoolData['desa_kelurahan'] = $this->normalizeText(
                $sekolah['desa_kelurahan'] ?? null
            );
        }

        DB::table('schools')->updateOrInsert(
            ['dapodik_sekolah_id' => $sekolahId],
            $schoolData
        );

        $this->info('  Data sekolah berhasil disinkronkan.');
    }

    private function syncGuru(): void
    {
        $this->info('Menyinkronkan data GTK...');
        $file = $this->dataPath . DIRECTORY_SEPARATOR . 'gtk.json';

        if (!File::exists($file)) {
            $this->warn('  gtk.json tidak ditemukan. Dilewati.');
            return;
        }

        $data = $this->readJson($file);
        if (!is_array($data)) {
            throw new \Exception('Format gtk.json tidak valid.');
        }

        $count = 0;
        $baru = 0;
        $diperbarui = 0;
        $softDeleted = 0;

        foreach ($data as $guru) {
            if (!is_array($guru)) continue;

            $ptkId = $guru['ptk_id'] ?? null;
            if (!$ptkId) continue;

            $gelarAkademik = $this->ambilGelarAkademik($guru);
            [$gelarDepan, $gelarBelakang] = $this->formatGelar($gelarAkademik);

            $existing = DB::table('teachers')->where('dapodik_ptk_id', $ptkId)->first();

            $namaNormal = $this->normalizeText($guru['nama'] ?? null);
            $namaLengkap = trim(($gelarDepan ? $gelarDepan . ' ' : '') . $namaNormal . ($gelarBelakang ? ', ' . $gelarBelakang : ''));

            $updateData = [
                'ptk_terdaftar_id'          => $guru['ptk_terdaftar_id'] ?? null,
                'nama'                      => $namaNormal,
                'nama_lengkap'              => $namaLengkap,
                'jenis_kelamin'             => $guru['jenis_kelamin'] ?? null,
                'tempat_lahir'              => $this->normalizeText($guru['tempat_lahir'] ?? null),
                'tanggal_lahir'             => $guru['tanggal_lahir'] ?? null,
                'agama_id_str'              => $this->normalizeText($guru['agama_id_str'] ?? null),
                'nuptk'                     => $guru['nuptk'] ?? null,
                'nik'                       => $guru['nik'] ?? null,
                'nip'                       => $guru['nip'] ?? null,
                'jenis_ptk'                 => $this->normalizeText($guru['jenis_ptk_id_str'] ?? null),
                'jenis_ptk_id_str'          => $this->normalizeText($guru['jenis_ptk_id_str'] ?? null),
                'jabatan_ptk'               => $this->normalizeText($guru['jabatan_ptk_id_str'] ?? null),
                'jabatan_ptk_id_str'        => $this->normalizeText($guru['jabatan_ptk_id_str'] ?? null),
                'status_kepegawaian'        => $this->normalizeText($guru['status_kepegawaian_id_str'] ?? null),
                'status_kepegawaian_id_str' => $this->normalizeText($guru['status_kepegawaian_id_str'] ?? null),
                'pendidikan_terakhir'       => $this->normalizeText($guru['pendidikan_terakhir'] ?? null),
                'bidang_studi_terakhir'     => $this->normalizeText($guru['bidang_studi_terakhir'] ?? null),
                'pangkat_golongan_terakhir' => $guru['pangkat_golongan_terakhir'] ?? null,
                'ptk_induk'                 => $guru['ptk_induk'] ?? null,
                'tanggal_surat_tugas'       => $guru['tanggal_surat_tugas'] ?? null,
                'tahun_ajaran_id'           => $guru['tahun_ajaran_id'] ?? null,
                'rwy_pend_formal'           => isset($guru['rwy_pend_formal']) ? json_encode($guru['rwy_pend_formal']) : null,
                'rwy_kepangkatan'           => isset($guru['rwy_kepangkatan']) ? json_encode($guru['rwy_kepangkatan']) : null,
                'updated_at' => now(),
            ];

            if (!empty($gelarDepan)) {
                $updateData['gelar_depan'] = $gelarDepan;
            } elseif (!$existing) {
                $updateData['gelar_depan'] = null;
            }

            if (!empty($gelarBelakang)) {
                $updateData['gelar_belakang'] = $gelarBelakang;
            } elseif (!$existing) {
                $updateData['gelar_belakang'] = null;
            }

            if ($this->columnExists('teachers', 'gelar_akademik_dapodik') && !empty($gelarAkademik)) {
                $updateData['gelar_akademik_dapodik'] = $gelarAkademik;
            }

            if (!$existing) {
                $updateData['created_at'] = now();
                DB::table('teachers')->insert(['dapodik_ptk_id' => $ptkId, ...$updateData]);
                $baru++;
            } else {
                DB::table('teachers')->where('id', $existing->id)->update($updateData);
                if ($this->columnExists('teachers', 'deleted_at') && !empty($existing->deleted_at)) {
                    $softDeleted++;
                }
                $diperbarui++;
            }
            $count++;
        }

        $this->info("  {$count} GTK diproses.");
        $this->info("     - {$baru} GTK baru.");
        $this->info("     - {$diperbarui} GTK diperbarui.");
        if ($softDeleted > 0) {
            $this->info("     - {$softDeleted} GTK soft-delete tetap dipertahankan.");
        }
    }

    private function ambilGelarAkademik(array $guru): ?string
    {
        $riwayat = $guru['rwy_pend_formal'] ?? [];
        if (!is_array($riwayat)) return null;

        $terakhir = null;
        foreach ($riwayat as $pendidikan) {
            if (!is_array($pendidikan)) continue;
            $gelar = trim((string)($pendidikan['gelar_akademik_id_str'] ?? ''));
            if ($gelar !== '') $terakhir = $gelar;
        }
        return $terakhir;
    }

    private function formatGelar(?string $gelar): array
    {
        if (!$gelar) return [null, null];
        $gelar = trim($gelar);
        $map = [
            'Sarjana Sains' => 'S.Si.', 'Sarjana Pendidikan' => 'S.Pd.', 'Sarjana Agama' => 'S.Ag.',
            'Sarjana Kehutanan' => 'S.Hut.', 'Sarjana Pertanian' => 'S.P.', 'Sarjana Peternakan' => 'S.Pt.',
            'Sarjana Pendidikan Islam' => 'S.Pd.I.', 'Sarjana Ekonomi' => 'S.E.', 'Sarjana Hukum' => 'S.H.',
            'Sarjana Teknik' => 'S.T.', 'Sarjana Komputer' => 'S.Kom.', 'Sarjana Sosial' => 'S.Sos.',
            'Sarjana Administrasi Pendidikan' => 'S.A.Pd.', 'Magister Pendidikan' => 'M.Pd.', 'Magister Sains' => 'M.Si.',
            'Magister Manajemen' => 'M.M.', 'Magister Ekonomi' => 'M.E.', 'Magister Hukum' => 'M.H.',
            'Magister Teknik' => 'M.T.', 'Magister Komputer' => 'M.Kom.', 'Doktor' => 'Dr.',
        ];
        $singkatan = $map[$gelar] ?? null;
        if (!$singkatan) {
            if (str_contains($gelar, '.') || preg_match('/^[A-Za-z.]+$/', $gelar)) $singkatan = $gelar;
        }
        if ($gelar === 'Doktor') return ['Dr.', null];
        return [null, $singkatan];
    }

    private function syncSiswa(): void
    {
        $this->info('Menyinkronkan data siswa...');
        $file = $this->dataPath . DIRECTORY_SEPARATOR . 'peserta_didik.json';

        if (!File::exists($file)) {
            $this->warn('  peserta_didik.json tidak ditemukan. Dilewati.');
            return;
        }

        $data = $this->readJson($file);
        if (!is_array($data)) {
            throw new \Exception('Format peserta_didik.json tidak valid.');
        }

        $count = 0;
        $baru = 0;
        $diperbarui = 0;

        foreach ($data as $siswa) {
            if (!is_array($siswa)) continue;
            $id = $siswa['peserta_didik_id'] ?? null;
            if (!$id) continue;

            $existing = DB::table('students')->where('dapodik_peserta_didik_id', $id)->first();

            $studentData = [
                'nama'                   => $this->normalizeText($siswa['nama'] ?? null),
                'nisn'                   => $siswa['nisn'] ?? null,
                'nipd'                   => $siswa['nipd'] ?? null,
                'jenis_kelamin'          => $siswa['jenis_kelamin'] ?? null,
                'nik'                    => $siswa['nik'] ?? null,
                'tempat_lahir'           => $this->normalizeText($siswa['tempat_lahir'] ?? null),
                'tanggal_lahir'          => $siswa['tanggal_lahir'] ?? null,
                'agama'                  => $this->normalizeText($siswa['agama_id_str'] ?? null),
                'alamat_jalan'           => $this->normalizeText($siswa['alamat_jalan'] ?? null),
                'nomor_telepon_rumah'    => $siswa['nomor_telepon_rumah'] ?? null,
                'nomor_telepon_seluler'  => $siswa['nomor_telepon_seluler'] ?? null,
                'email'                  => $siswa['email'] ?? null,
                'sekolah_asal'           => $this->normalizeText($siswa['sekolah_asal'] ?? null),
                'tanggal_masuk_sekolah'  => $siswa['tanggal_masuk_sekolah'] ?? null,
                'jenis_pendaftaran'      => $this->normalizeText($siswa['jenis_pendaftaran_id_str'] ?? null),
                'nama_ayah'              => $this->normalizeText($siswa['nama_ayah'] ?? null),
                'pekerjaan_ayah'         => $this->normalizeText($siswa['pekerjaan_ayah_id_str'] ?? null),
                'nama_ibu'               => $this->normalizeText($siswa['nama_ibu'] ?? null),
                'pekerjaan_ibu'          => $this->normalizeText($siswa['pekerjaan_ibu_id_str'] ?? null),
                'nama_wali'              => $this->normalizeText($siswa['nama_wali'] ?? null),
                'pekerjaan_wali'         => $this->normalizeText($siswa['pekerjaan_wali_id_str'] ?? null),
                'anak_keberapa'          => $siswa['anak_keberapa'] ?? null,
                'tinggi_badan'           => $siswa['tinggi_badan'] ?? null,
                'berat_badan'            => $siswa['berat_badan'] ?? null,
                'kebutuhan_khusus'       => $this->normalizeText($siswa['kebutuhan_khusus'] ?? null),
                'updated_at'             => now(),
            ];

            if (!$existing) {
                $studentData['created_at'] = now();
                DB::table('students')->insert(['dapodik_peserta_didik_id' => $id, ...$studentData]);
                $baru++;
            } else {
                DB::table('students')->where('id', $existing->id)->update($studentData);
                $diperbarui++;
            }
            $count++;
        }

        $this->info("  {$count} siswa diproses.");
        $this->info("     - {$baru} siswa baru.");
        $this->info("     - {$diperbarui} siswa diperbarui.");
    }

    private function syncRombel(): void
    {
        $this->info('Menyinkronkan rombel, anggota rombel, mapel, dan pembelajaran...');
        $file = $this->dataPath . DIRECTORY_SEPARATOR . 'rombel.json';

        if (!File::exists($file)) {
            $this->warn('  rombel.json tidak ditemukan. Dilewati.');
            return;
        }

        $data = $this->readJson($file);
        if (!is_array($data)) {
            throw new \Exception('Format rombel.json tidak valid.');
        }

        $teachers = DB::table('teachers')->pluck('id', 'dapodik_ptk_id')->toArray();
        $students = DB::table('students')->pluck('id', 'dapodik_peserta_didik_id')->toArray();

        $rombelCount = 0;
        $anggotaCount = 0;
        $pembelajaranCount = 0;

        foreach ($data as $r) {
            if (!is_array($r)) continue;
            $rombelId = $r['rombongan_belajar_id'] ?? null;
            if (!$rombelId) continue;

            $teacherId = null;
            if (!empty($r['ptk_id']) && isset($teachers[$r['ptk_id']])) {
                $teacherId = $teachers[$r['ptk_id']];
            }

            $existing = DB::table('homerooms')->where('dapodik_rombel_id', $rombelId)->first();

            $rombelData = [
                'nama'                  => $r['nama'] ?? null,
                'tingkat_pendidikan'    => $this->normalizeText($r['tingkat_pendidikan_id_str'] ?? null),
                'semester_id'           => $r['semester_id'] ?? null,
                'jenis_rombel'          => $this->normalizeText($r['jenis_rombel_str'] ?? null),
                'kurikulum'             => $this->normalizeText($r['kurikulum_id_str'] ?? null),
                'homeroom_teacher_id'   => $teacherId,
                'jurusan'               => $this->normalizeText($r['jurusan_id_str'] ?? null),
                'updated_at'            => now(),
            ];

            if (!$existing) {
                $rombelData['created_at'] = now();
                DB::table('homerooms')->insert(['dapodik_rombel_id' => $rombelId, ...$rombelData]);
            } else {
                DB::table('homerooms')->where('id', $existing->id)->update($rombelData);
            }

            $homeroomId = DB::table('homerooms')->where('dapodik_rombel_id', $rombelId)->value('id');
            $rombelCount++;

            foreach (($r['anggota_rombel'] ?? []) as $anggota) {
                if (!is_array($anggota)) continue;
                $studentDapodikId = $anggota['peserta_didik_id'] ?? null;
                $anggotaId = $anggota['anggota_rombel_id'] ?? null;
                if (!$studentDapodikId || !$anggotaId || !isset($students[$studentDapodikId])) continue;

                DB::table('enrollments')->updateOrInsert(
                    ['dapodik_anggota_rombel_id' => $anggotaId],
                    [
                        'student_id' => $students[$studentDapodikId],
                        'homeroom_id' => $homeroomId,
                        'jenis_pendaftaran' => $this->normalizeText($anggota['jenis_pendaftaran_id_str'] ?? null),
                        'updated_at' => now(),
                    ]
                );
                $anggotaCount++;
            }

            foreach (($r['pembelajaran'] ?? []) as $pemb) {
                if (!is_array($pemb)) continue;
                $teacherDapodikId = $pemb['ptk_id'] ?? null;
                if (!$teacherDapodikId || !isset($teachers[$teacherDapodikId])) continue;

                $subjectDapodikId = isset($pemb['mata_pelajaran_id']) ? (string)$pemb['mata_pelajaran_id'] : null;
                if (!$subjectDapodikId) continue;

                $subjectData = [
                    'nama_mata_pelajaran' => $this->normalizeText($pemb['nama_mata_pelajaran'] ?? null),
                    'updated_at' => now(),
                ];

                $subjectExists = DB::table('subjects')->where('dapodik_subject_id', $subjectDapodikId)->exists();
                if (!$subjectExists) $subjectData['created_at'] = now();

                DB::table('subjects')->updateOrInsert(['dapodik_subject_id' => $subjectDapodikId], $subjectData);
                $subjectId = DB::table('subjects')->where('dapodik_subject_id', $subjectDapodikId)->value('id');

                $assignmentId = $pemb['pembelajaran_id'] ?? null;
                if (!$assignmentId) continue;

                $assignmentExists = DB::table('teaching_assignments')->where('dapodik_pembelajaran_id', $assignmentId)->exists();
                $assignmentData = [
                    'homeroom_id' => $homeroomId,
                    'teacher_id' => $teachers[$teacherDapodikId],
                    'subject_id' => $subjectId,
                    'jam_mengajar_per_minggu' => $pemb['jam_mengajar_per_minggu'] ?? null,
                    'status_di_kurikulum' => $this->normalizeText($pemb['status_di_kurikulum_str'] ?? null),
                    'updated_at' => now(),
                ];

                if (!$assignmentExists) $assignmentData['created_at'] = now();

                DB::table('teaching_assignments')->updateOrInsert(
                    ['dapodik_pembelajaran_id' => $assignmentId],
                    $assignmentData
                );
                $pembelajaranCount++;
            }
        }

        $this->info("  {$rombelCount} rombel diproses.");
        $this->info("  {$anggotaCount} anggota rombel diproses.");
        $this->info("  {$pembelajaranCount} pembelajaran diproses.");
    }

    private function generateUserAccounts(): void
    {
        $this->info('Menyiapkan akun login GTK dan siswa...');
        $npsn = DB::table('schools')->value('npsn');
        if (!$npsn) {
            $this->warn('  NPSN tidak ditemukan. Pembuatan akun dilewati.');
            return;
        }

        $defaultPassword = Hash::make($npsn);
        $gurus = DB::table('teachers')->get();
        $guruCount = 0;

        foreach ($gurus as $guru) {
            $loginId = $guru->nip ?? $guru->nik ?? $guru->nuptk ?? null;
            if (!$loginId || trim((string)$loginId) === '') continue;

            $loginId = trim((string)$loginId);
            $jenisPtk = strtolower(trim((string)($guru->jenis_ptk ?? '')));
            $jabatanPtk = strtolower(trim((string)($guru->jabatan_ptk ?? '')));

            $role = 'tendik';
            if (str_contains($jenisPtk, 'guru') || str_contains($jenisPtk, 'konselor') || str_contains($jenisPtk, 'bk')) {
                $role = 'guru';
            }
            if (str_contains($jenisPtk, 'konselor') || str_contains($jenisPtk, 'guru bimbingan') || str_contains($jenisPtk, 'guru bk') || str_contains($jabatanPtk, 'konselor') || str_contains($jabatanPtk, 'bimbingan konseling')) {
                $role = 'guru_bk';
            }
            if (str_contains($jenisPtk, 'kepala sekolah') || str_contains($jabatanPtk, 'kepala sekolah')) {
                $role = 'kepala_sekolah';
            }

            $existingUser = DB::table('users')->where('login_id', $loginId)->first();
            if ($existingUser) {
                DB::table('users')->where('id', $existingUser->id)->update([
                    'name' => $guru->nama,
                    'role' => $role,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('users')->insert([
                    'name' => $guru->nama,
                    'login_id' => $loginId,
                    'email' => $loginId . '@eduxid.id',
                    'password' => $defaultPassword,
                    'role' => $role,
                    'must_change_password' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $guruCount++;
        }

        $siswas = DB::table('students')->whereNotNull('nisn')->get();
        $siswaCount = 0;

        foreach ($siswas as $siswa) {
            $loginId = trim((string)$siswa->nisn);
            if ($loginId === '') continue;

            $existingUser = DB::table('users')->where('login_id', $loginId)->first();
            if ($existingUser) {
                DB::table('users')->where('id', $existingUser->id)->update([
                    'name' => $siswa->nama,
                    'role' => 'siswa',
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('users')->insert([
                    'name' => $siswa->nama,
                    'login_id' => $loginId,
                    'email' => $loginId . '@eduxid.id',
                    'password' => $defaultPassword,
                    'role' => 'siswa',
                    'must_change_password' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $siswaCount++;
        }

        $this->info("  {$guruCount} akun GTK diproses.");
        $this->info("  {$siswaCount} akun siswa diproses.");
    }

    private function readJson(string $file): array
    {
        $content = File::get($file);
        $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        if (is_array($data) && isset($data['data']) && is_array($data['data'])) {
            return $data['data'];
        }

        return is_array($data) ? $data : [];
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            return Schema::hasColumn($table, $column);
        } catch (\Throwable) {
            return false;
        }
    }
}
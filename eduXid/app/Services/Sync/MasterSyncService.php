<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/*
 * REWRITE-05.1 (rev.2): MASTER SYNC SERVICE
 *
 * Satu pintu penerimaan master untuk tiga jalur:
 *   1. LAN  : GET http://{server_ip}:8000/api/local/master-data
 *   2. Cloud: GET {cloud}/api/teacher/master-data (via CloudMasterDataService)
 *   3. File : m2g_*.json (flashdisk)
 *
 * Ketiganya berakhir di MasterApplier::apply() - verifikasi hash,
 * guard versi, guard NPSN, upsert-by-id, rekonsiliasi, dan pencatatan
 * versi terpasang berlaku SAMA untuk semua jalur.
 *
 * rev.2 (temuan pemeriksaan):
 *   1. [FIX-HEADER] login_id disanitasi + divalidasi sebelum masuk
 *      header HTTP - mencegah header injection & karakter liar dari
 *      data warisan.
 *   2. [FIX-TIMEOUT] connectTimeout dipisah (pelajaran cURL 28 yang
 *      menggantung 20 menit): fase koneksi/DNS dibatasi 10 detik.
 *   3. [FIX-SIZE] File m2g dibatasi 200 MB - payload lebih besar
 *      berarti korup/salah file, bukan master sah; mencegah OOM.
 *   4. [FIX-DUP] Insert akun tahan-duplikat - guru dengan identitas
 *      ganda (data warisan) tidak lagi menggagalkan seluruh pembuatan
 *      akun; konflik dicatat dan dilewati.
 *   5. [LOG-VERSI] Sumber & versi master dicatat saat apply -
 *      menjawab "laptop guru ini pakai master versi berapa?".
 *
 * Service TIDAK menyentuh purge admin / flash message - itu tetap
 * di controller agar perilaku keamanan & UX tidak berubah.
 */
class MasterSyncService
{
    /** Batas ukuran file m2g (byte). 200 MB jauh melebihi kebutuhan. */
    protected const MAX_M2G_BYTES = 200 * 1024 * 1024;

    public function __construct(
        protected MasterApplier $applier,
        protected CloudMasterDataService $cloudMaster,
        protected LmsSyncService $sync
    ) {
    }

    /* ============================================================
     * JALUR 1 - LAN
     * ============================================================ */

    /**
     * Tarik master dari laptop admin (server sekolah).
     *
     * @param string $serverIp IP server (dari app_settings.server_ip)
     * @param string|null $loginId identitas peminta (audit) - DISANITASI
     */
    public function applyFromLan(string $serverIp, ?string $loginId = null): array
    {
        $ip = trim($serverIp);
        $configuredUrl = trim((string) config('eduxid.lan_master_url', ''));
        if ($configuredUrl !== '') {
            $parts = parse_url($configuredUrl);
            if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
                || isset($parts['fragment']) || isset($parts['query'])) {
                throw new \RuntimeException('EDUXID_LAN_MASTER_URL wajib HTTPS valid tanpa kredensial/query.');
            }
            $masterUrl = rtrim($configuredUrl, '/') . '/api/local/master-data';
        } else {
            if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) {
                throw new \RuntimeException('IP server tidak valid.');
            }
            // Explicit lab-only override for legacy LAN servers. Disabled by
            // default so NIK/NIP and bearer token cannot silently traverse HTTP.
            if (!config('eduxid.allow_insecure_lan_master', false)) {
                throw new \RuntimeException(
                    'Penarikan master LAN memerlukan HTTPS. Konfigurasikan EDUXID_LAN_MASTER_URL '
                    . 'atau gunakan jalur Cloud/offline. HTTP biasa diblokir demi keamanan data.'
                );
            }
            $masterUrl = "http://{$ip}:8000/api/local/master-data";
            Log::warning('Master data melalui HTTP LAN legacy: koneksi tidak terenkripsi.');
        }
        $token = trim((string) config('eduxid.sync_token', ''));
        if ($token === '' || hash_equals('eduxid_secret_2024', $token)) {
            throw new \RuntimeException('Sync token LAN unik wajib diatur sebelum penarikan master.');
        }

        $safeLoginId = $this->sanitizeHeaderToken($loginId);

        $headers = ['X-Sync-Token' => $token];

        if ($safeLoginId !== null) {
            $headers['X-Login-Id'] = $safeLoginId;
        }

        $response = Http::withHeaders($headers)
            ->timeout(90)
            ->connectTimeout(10)
            ->get($masterUrl);

        if (!$response->successful()) {
            throw new \RuntimeException(
                'Server menolak/menggagalkan penarikan master. HTTP '
                . $response->status() . ' | ' . mb_substr($response->body(), 0, 300)
            );
        }

        $data = $response->json();

        if (!is_array($data) || !isset($data['gurus'])) {
            throw new \RuntimeException('Respons master dari server tidak valid.');
        }

        $result = $this->applyPayload($data);

        $this->logApplied('LAN', $result);

        return $result;
    }

    /* ============================================================
     * JALUR 2 - CLOUD
     * ============================================================ */

    /**
     * Tarik snapshot master dari eduXid Cloud (wajib pairing).
     */
    public function applyFromCloud(): array
    {
        $snapshot = $this->cloudMaster->pullSnapshot();

        $master = $snapshot['master'] ?? null;

        if (!is_array($master) || !isset($master['gurus'])) {
            throw new \RuntimeException('Snapshot master di Cloud tidak valid.');
        }

        // Cloud V21 is redacted: preserve the private roster supplied locally
        // in the signed GTK bundle. Never reconcile/remove private rows from it.
        if (($master['metadata']['cloud_projection'] ?? null) !== 'v21-opaque') {
            throw new \RuntimeException('BLOCKED V21: snapshot Cloud tidak bertanda proyeksi anonim; jangan terapkan master lama.');
        }
        $result = $this->applyPayload($master, true);

        $this->logApplied('CLOUD', $result);

        return $result;
    }

    /* ============================================================
     * JALUR 3 - FILE m2g
     * ============================================================ */

    /**
     * Impor master dari file m2g_*.json (flashdisk).
     */
    public function applyFromM2gFile(string $absolutePath): array
    {
        if (!is_file($absolutePath)) {
            throw new \RuntimeException('File master tidak ditemukan: ' . $absolutePath);
        }

        $size = filesize($absolutePath);

        if ($size === false || $size === 0) {
            throw new \RuntimeException('File master kosong atau tidak dapat dibaca.');
        }

        if ($size > self::MAX_M2G_BYTES) {
            throw new \RuntimeException(
                'File master terlalu besar (' . round($size / 1048576, 1)
                . ' MB, batas 200 MB) - kemungkinan bukan file m2g yang sah.'
            );
        }

        $raw = file_get_contents($absolutePath);
        $data = json_decode((string) $raw, true);

        if (!is_array($data)
            || !isset($data['gurus'])
            || (($data['metadata']['tipe_file'] ?? '') !== 'm2g_master')) {
            throw new \RuntimeException(
                'File JSON tidak valid. Gunakan file m2g_ dari eduXid.'
            );
        }

        $result = $this->applyPayload($data);

        $this->logApplied('M2G', $result);

        return $result;
    }

    /* ============================================================
     * AKUN LOGIN DARI MASTER (idempoten)
     * ============================================================ */

    /**
     * Buat akun login dari teachers + students hasil snapshot.
     *
     * - Hanya membuat yang BELUM ada (idempoten; role/nama milik
     *   admin tidak ditimpa).
     * - Tahan-duplikat: bila identitas ganda dari data warisan
     *   menabrak unique index (email/login), konflik dicatat dan
     *   dilewati - TIDAK menggagalkan sisa akun.
     */
    public function syncUsersFromMaster(): void
    {
        $npsn = DB::table('schools')->value('npsn');

        if (!$npsn) {
            return;
        }

        $defaultPassword = \Illuminate\Support\Facades\Hash::make($npsn);

        $gurus = DB::table('teachers')->get();

        foreach ($gurus as $guru) {
            $loginId = null;

            foreach ([$guru->nip ?? null, $guru->nik ?? null, $guru->nuptk ?? null] as $cand) {
                if (trim((string) $cand) !== '') {
                    $loginId = trim((string) $cand);
                    break;
                }
            }

            if ($loginId === null) {
                continue;
            }

            $existing = DB::table('users')->where('login_id', $loginId)->first();

            if ($existing) {
                continue;
            }

            $role = 'guru';
            $jenisPtk = strtolower((string) ($guru->jenis_ptk ?? ''));
            $jabatanPtk = strtolower((string) ($guru->jabatan_ptk ?? ''));

            if (str_contains($jenisPtk, 'bk') || str_contains($jenisPtk, 'konselor')) {
                $role = 'guru_bk';
            } elseif (str_contains($jabatanPtk, 'kepala sekolah')) {
                $role = 'kepala_sekolah';
            } elseif (
                str_contains($jenisPtk, 'tendik')
                || str_contains($jenisPtk, 'tenaga kependidikan')
                || str_contains($jabatanPtk, 'tata usaha')
                || str_contains($jabatanPtk, 'staf')
            ) {
                $role = 'tendik';
            }

            try {
                DB::table('users')->insert([
                    'name'                 => $guru->nama,
                    'login_id'             => $loginId,
                    'email'                => $loginId . '@eduxid.id',
                    'password'             => $defaultPassword,
                    'role'                 => $role,
                    'must_change_password' => true,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            } catch (Throwable $e) {
                /*
                 * [FIX-DUP] Konflik unique (login_id/email ganda dari
                 * data warisan) tidak boleh menggagalkan sisa akun.
                 */
                Log::warning('[MASTER-SYNC] Insert akun guru ditolak (duplikat?).', [
                    'login_id' => $loginId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        $siswas = DB::table('students')->get();

        foreach ($siswas as $siswa) {
            if (!$siswa->nisn || trim((string) $siswa->nisn) === '') {
                continue;
            }

            $existing = DB::table('users')->where('login_id', $siswa->nisn)->first();

            if ($existing) {
                continue;
            }

            try {
                DB::table('users')->insert([
                    'name'                 => $siswa->nama,
                    'login_id'             => $siswa->nisn,
                    'email'                => $siswa->nisn . '@eduxid.id',
                    'password'             => $defaultPassword,
                    'role'                 => 'siswa',
                    'must_change_password' => true,
                    'created_at'           => now(),
                    'updated_at'           => now(),
                ]);
            } catch (Throwable $e) {
                Log::warning('[MASTER-SYNC] Insert akun siswa ditolak (duplikat?).', [
                    'login_id' => $siswa->nisn,
                    'error'    => $e->getMessage(),
                ]);
            }
        }
    }

    /* ============================================================
     * INTERNAL
     * ============================================================ */

    /**
     * Inti ketiga jalur: validasi bentuk, lalu MasterApplier
     * (hash, versi, NPSN, upsert, rekonsiliasi).
     */
    protected function applyPayload(array $master, bool $cloudOpaque = false): array
    {
        foreach (MasterSnapshotService::TABLE_MAP as $key => $table) {
            if (!isset($master[$key]) || !is_array($master[$key])) {
                throw new \RuntimeException(
                    "Snapshot tidak memuat kunci '{$key}' - payload tidak lengkap."
                );
            }
        }

        return $this->applier->apply($master, $cloudOpaque ? ['cloud_opaque' => true, 'reconcile' => false] : []);
    }

    /**
     * [LOG-VERSI] Satu baris log per apply - memudahkan audit
     * "laptop ini sudah di master versi berapa, dari sumber mana".
     */
    protected function logApplied(string $source, array $result): void
    {
        Log::info('[MASTER-SYNC] Master diterapkan.', [
            'sumber'  => $source,
            'status'  => $result['status'] ?? 'applied',
            'versi'   => $result['version'] ?? null,
            'hash'    => substr((string) ($result['hash'] ?? ''), 0, 12),
        ]);
    }

    /**
     * [FIX-HEADER] Disanitasi untuk header HTTP: hanya huruf, angka,
     * titik, garis bawah, strip; maksimal 64 karakter. Bila identitas
     * mengandung karakter lain (data warisan), dikirim tanpa identitas
     * (audit kehilangan granularitas, bukan kegagalan fungsi).
     */
    protected function sanitizeHeaderToken(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9._-]{1,64}$/', $value) !== 1) {
            return null;
        }

        return $value;
    }
}
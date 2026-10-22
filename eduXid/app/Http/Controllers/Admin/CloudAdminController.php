<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Sync\EduxidCloudConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * ============================================================
 * [RAPOR-SYNC] Panel eduXid Cloud (admin).
 *
 * Worker Cloud (versi terbaru) WAJIB memuat:
 * - TEACHER_ROUTED_ENTITIES 9 entity (final_grade, daily_grade,
 *   bk_log_batch, catatan_wali_batch, rapor_recap_batch,
 *   ekskul_grade_batch, kokurikuler_grade_batch, dst.)
 * - Endpoint /api/school/master-data/sync & /api/teacher/master-data
 *   untuk fitur #2 (Tarik/Push Data Pokok via Cloud).
 * - Endpoint /api/admin/device/resync untuk tombol resync.
 *
 * Bila worker masih versi lama: tombol-tombol akan menampilkan
 * pesan error yang informatif (bukan 500) karena respons tanpa
 * 'data' dideteksi sebagai catch-all (lihat callCloud).
 * ============================================================
 */
class CloudAdminController extends Controller
{
    /**
     * Timeout HTTP untuk callCloud — dipusatkan agar mudah
     * disesuaikan bila koneksi ke Cloud lambat di lapangan.
     */
    protected const CLOUD_TIMEOUT = 30;

    /*
     * ============================================================
     * PANEL EDUXID CLOUD — SEMUA METHOD HANYA SATU KALI:
     * index, setupStatus, setupSave, dashboardCardStatus,
     * createEnrollment, resyncDevice, pushStudentAccounts,
     * pushTeacherAccounts, callCloud, httpOptions
     * ============================================================
     */

    public function index(): View
    {
        return view('admin.eduxid-cloud', [
            'eduxidStatus' => EduxidCloudConfig::status(),
        ]);
    }

    /*
     * [G1] Status koneksi Cloud — dipakai tombol "Uji Koneksi"
     * di dashboard admin & panel.
     */
    public function setupStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => EduxidCloudConfig::status(),
        ]);
    }

    /*
     * [G1] Simpan kunci admin sekolah (edx_sch_...) ke database lokal.
     * URL eduXid Cloud dikelola internal aplikasi dan sengaja tidak
     * diterima dari browser/panel Admin.
     */
    public function setupSave(Request $request): JsonResponse
    {
        // V21R124: password-encrypted backup/recovery of identity + pairing.
        // Existing admin-auth + CSRF route is reused;
        // routes/web.php and protected Donasi paths remain unchanged.
        $identityAction = trim((string) $request->input('identity_backup_action', ''));
        if ($identityAction !== '') {
            try {
                \App\Services\Security\SchoolIdentityEncryptedBackupV21R124::localConsole($request);
                if ($identityAction === 'export') {
                    $password = $request->input('backup_password');
                    if (!is_string($password)) throw new RuntimeException('Kata sandi cadangan wajib diisi.');
                    $data = \App\Services\Security\SchoolIdentityEncryptedBackupV21R124::exportEncrypted($password);
                    return response()->json(['success' => true, 'data' => $data])
                        ->header('Cache-Control', 'no-store, private, max-age=0')
                        ->header('Pragma', 'no-cache')
                        ->header('X-Content-Type-Options', 'nosniff');
                }
                if ($identityAction === 'restore') {
                    $file = $request->input('identity_encrypted_file');
                    $password = $request->input('backup_password');
                    if (!is_string($file) || strlen($file) > 16384 || !is_string($password)) {
                        throw new RuntimeException('File cadangan terenkripsi dan kata sandi wajib tersedia.');
                    }
                    $data = \App\Services\Security\SchoolIdentityEncryptedBackupV21R124::restoreEncrypted($file, $password, $request->input('backup_pairing_override'));
                    return response()->json(['success' => true, 'data' => $data, 'message' => $data['message']])
                        ->header('Cache-Control', 'no-store, private, max-age=0')
                        ->header('Pragma', 'no-cache');
                }
                throw new RuntimeException('Operasi cadangan tidak dikenal.');
            } catch (\Throwable $e) {
                return response()->json(['success' => false, 'message' => $e->getMessage()], 422)
                    ->header('Cache-Control', 'no-store, private, max-age=0');
            }
        }

        $validated = $request->validate([
            'admin_token' => 'nullable|string|max:255',
        ]);

        EduxidCloudConfig::set(
            'admin_token',
            $validated['admin_token'] ?? null
        );

        $status = EduxidCloudConfig::status();

        return response()->json([
            'success' => true,
            'message' => $status['ready']
                ? 'Kunci tersimpan. eduXid Cloud siap digunakan.'
                : 'Kunci tersimpan, tetapi konfigurasi belum lengkap.',
            'status' => $status,
        ]);
    }

    /*
     * [G1] Status untuk kartu dashboard admin.
     */
    public function dashboardCardStatus(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'status' => EduxidCloudConfig::status(),
        ]);
    }

    /**
     * Buat enrollment token untuk pairing laptop guru.
     *
     * [CREATE-ENROLLMENT-FIX] Validasi enrollment_code eksplisit —
     * sebelumnya bisa mengembalikan string kosong yang membingungkan
     * operator bila worker merespons sukses tapi body-nya tidak lengkap.
     */
    public function createEnrollment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'max_uses' => 'nullable|integer|min:0',
            'expires_at' => 'nullable|date|after:now',
        ]);

        try {
            $result = $this->callCloud(
                'POST',
                '/api/admin/enrollment/create',
                [
                    'school_id' => trim((string) DB::table('schools')->value('npsn')),
                    'max_uses' => (int) ($validated['max_uses'] ?? 0),
                    'expires_at' => isset($validated['expires_at']) ? $validated['expires_at'] : null,
                ]
            );

            $data = $result['data'] ?? [];

            $enrollmentCode = trim((string) ($data['enrollment_code'] ?? ''));

            if ($enrollmentCode === '') {
                throw new RuntimeException(
                    'Cloud merespons sukses namun tidak mengembalikan enrollment_code. '
                    . 'Periksa versi Worker — endpoint enrollment/create mungkin belum lengkap.'
                );
            }

            return response()->json([
                'success' => true,
                'enrollment_code' => $enrollmentCode,
                'max_uses' => (int) ($data['max_uses'] ?? 0),
                'expires_at' => $data['expires_at'] ?? null,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Re-queue ulang seluruh event ACKNOWLEDGED milik satu device.
     *
     * [FIX-1] Endpoint /api/admin/device/resync kini ADA di Worker.
     * [FIX-3] Melewati callCloud() — konsisten admin key, timeout, CA.
     * [G1] Worker menolak bila device milik sekolah lain terhadap
     * kunci ter-scope.
     *
     * [UX-FIX] requeued_events = 0 bukan error — informasikan ke
     * operator dengan jelas (device baru atau tidak ada event tertunda).
     */
    public function resyncDevice(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_id' => 'required|string|max:255',
        ]);

        try {
            $data = $this->callCloud(
                'POST',
                '/api/admin/device/resync',
                [
                    'device_id' => trim($validated['device_id']),
                ]
            );

            if (!array_key_exists('requeued_events', $data['data'] ?? [])) {
                throw new RuntimeException(
                    'Cloud menjawab tanpa hasil requeue — endpoint resync belum tersedia di Worker (deploy versi Worker terbaru).'
                );
            }

            $requeued = (int) ($data['data']['requeued_events'] ?? 0);

            return response()->json([
                'success' => true,
                'requeued' => $requeued,
                'message' => $requeued > 0
                    ? "Berhasil mengantre ulang {$requeued} event untuk device ini."
                    : 'Tidak ada event tertunda — device ini sudah sinkron penuh atau belum pernah menerima data.',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Push direktori akun siswa ke EDUXID CLOUD.
     *
     * [FIX-2] Kunci hasil yang benar adalah 'upserted'.
     *
     * [TIMEOUT-FIX] Windows menghitung max_execution_time sebagai
     * wall-clock (termasuk waktu menunggu HTTP). Push 400+ siswa
     * beruntun melebihi 30 dtk default → script dibunuh di tengah
     * batch. set_time_limit() khusus request ini, TIDAK mengubah
     * php.ini maupun request lain.
     */
    public function pushStudentAccounts(Request $request): JsonResponse
    {
        @set_time_limit(600);

        try {
            $result = app(\App\Services\Sync\LmsSyncService::class)
                ->pushStudentAccountDirectory();

            if (($result['success'] ?? false) !== true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Push direktori akun gagal: ' . ($result['error'] ?? 'tidak diketahui.'),
                ], 422);
            }

            $pushed = (int) (
                $result['upserted']
                ?? $result['pushed']
                ?? 0
            );

            return response()->json([
                'success' => true,
                'pushed' => $pushed,
                'message' => 'Direktori akun murid berhasil disinkronkan (' . $pushed . ' akun).',
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Push direktori akun guru (W10b/W15).
     *
     * [TIMEOUT-FIX] set_time_limit khusus request ini — payload
     * assignments + roster bisa berat di sekolah besar.
     */
    public function pushTeacherAccounts(Request $request): JsonResponse
    {
        @set_time_limit(600);

        try {
            $result = app(\App\Services\Sync\LmsSyncService::class)
                ->pushTeacherAccountDirectory();

            if (($result['success'] ?? false) !== true) {
                return response()->json([
                    'success' => false,
                    'message' => 'Push direktori guru gagal: ' . ($result['error'] ?? 'tidak diketahui.'),
                ], 422);
            }

            $pushed = (int) (
                $result['data']['upserted']
                ?? $result['pushed']
                ?? 0
            );

            // V21R98: No more silent omissions of manual GTK. Worker ACK
            // remains authoritative for the number of Cloud accounts.
            $manual = $result['manual_gtk'] ?? [];
            $created = (int) ($manual['created'] ?? 0);
            $needsIdentity = (int) ($manual['needs_identity'] ?? 0);
            $needsReview = (int) ($manual['needs_reconciliation'] ?? 0);
            $notice = $created > 0 ? ' Akun lokal GTK manual baru disiapkan: '.$created.'.' : '';
            if ($needsIdentity > 0) {
                $notice .= ' GTK manual belum dapat dipublikasikan (NIP/NIK tidak lengkap atau tidak valid): '.$needsIdentity.'.';
            }
            if ($needsReview > 0) {
                $notice .= ' GTK manual memerlukan pemeriksaan identitas/akun duplikat: '.$needsReview.'.';
            }
            if (($result['skipped'] ?? []) !== []) {
                $notice .= ' Akun lain dilewati pada tahap direktori: '.count($result['skipped']).'.';
            }
            return response()->json([
                'success' => true,
                'warning' => $needsIdentity > 0 || $needsReview > 0 || ($result['skipped'] ?? []) !== [],
                'pushed' => $pushed,
                'manual_gtk' => [
                    'total' => (int) ($manual['manual_total'] ?? 0),
                    'created' => $created,
                    'needs_identity' => $needsIdentity,
                    'needs_reconciliation' => $needsReview,
                ],
                'message' => 'Direktori akun guru berhasil disinkronkan (' . $pushed . ' guru).' . $notice,
            ]);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }


    /**
     * ============================================================
     * [CLOUD-MASTER] #2 — Push snapshot Data Pokok ke Cloud.
     *
     * Payload dibangun oleh MasterSnapshotService — SATU SUMBER
     * KEBENARAN yang juga dipakai jalur LAN (eksporMasterApi)
     * dan file m2g (eksporMaster), sehingga ketiga jalur
     * distribusi master SELALU identik.
     *
     * Auth   : Admin Key (EduxidCloudConfig) — scoped G1 di Worker.
     * Target : R2 key master/{school_id}.json.
     *
     * [TIMEOUT-FIX] set_time_limit khusus request — snapshot sekolah
     * besar (ribuan siswa) berat di sisi memori + HTTP.
     * ============================================================
     */
    public function pushMasterDataCloud(Request $request): JsonResponse
    {
        @set_time_limit(600);

        try {
            ini_set('memory_limit', '-1');

            $result = app(\App\Services\Sync\CloudMasterDataService::class)->pushSnapshot();

            $counts = $result['counts'] ?? [];

            \Illuminate\Support\Facades\Log::info('[CLOUD-MASTER] Snapshot Data Pokok di-push ke Cloud.', [
                'version' => $result['version'] ?? null,
                'counts' => $counts,
                'triggered_by_user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => true,
                'version' => $result['version'] ?? null,
                'message' => '✅ Snapshot Data Pokok terkirim ke Cloud (versi ' . ($result['version'] ?? '-') . '). Isi: '
                    . ($counts['siswas'] ?? 0) . ' murid, ' . ($counts['gurus'] ?? 0) . ' GTK, '
                    . ($counts['rombels'] ?? 0) . ' rombel, ' . ($counts['pembelajaran'] ?? 0) . ' pembelajaran.',
            ]);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::error('[CLOUD-MASTER] Push master ke Cloud gagal.', [
                'error' => $e->getMessage(),
                'exception' => get_class($e),
                'triggered_by_user_id' => auth()->id(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Push master ke Cloud gagal: ' . $e->getMessage(),
            ], 422);
        }
    }

    /*
     * ============================================================
     * [G1] Kunci & URL via EduxidCloudConfig:
     *   1. Database (Setup Cloud — token sekolah edx_sch_...)
     *   2. .env fallback (EDUXID_ADMIN_TOKEN / EDUXID_ADMIN_KEY master)
     *
     * SATU guard tunggal — versi tempelan lama menduplikasi guard
     * master sehingga laptop sekolah ber-token saja selalu gagal.
     *
     * [CLOUD-TIMEOUT] Nilai timeout diambil dari konstanta
     * CLOUD_TIMEOUT — cukup ubah di satu tempat bila perlu.
     * ============================================================
     */
    protected function callCloud(string $method, string $path, array $body = []): array
    {
        $adminToken = EduxidCloudConfig::adminToken();

        if ($adminToken === '') {
            throw new RuntimeException(
                'Kunci admin eduXid belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud, tempel '
                . 'kunci dari operator pusat.'
            );
        }

        $baseUrl = EduxidCloudConfig::syncServer();

        if ($baseUrl === '') {
            throw new RuntimeException(
                'URL eduXid Cloud belum dikonfigurasi — '
                . 'buka menu Admin → eduXid Cloud → Setup Cloud.'
            );
        }

        $response = \Illuminate\Support\Facades\Http::timeout(self::CLOUD_TIMEOUT)
            ->acceptJson()
            ->asJson()
            ->withHeaders(['Authorization' => 'Admin ' . $adminToken])
            ->withOptions($this->httpOptions())
            ->{$method}($baseUrl . $path, $body);

        $data = $response->json();

        if ($response->failed() || !is_array($data) || ($data['success'] ?? false) !== true) {
            $detail = is_array($data) ? json_encode($data['error'] ?? $data, JSON_UNESCAPED_UNICODE) : $response->body();
            throw new RuntimeException('EDUXID CLOUD menolak request. HTTP ' . $response->status() . ': ' . $detail);
        }

        /*
         * [FIX-1] Route catch-all Cloud mengembalikan
         * {success:true, service, endpoint} tanpa 'data'.
         */
        if (!array_key_exists('data', $data)) {
            throw new RuntimeException(
                'EDUXID CLOUD tidak mengenali endpoint ini'
                . (isset($data['endpoint']) ? ' (' . $data['endpoint'] . ')' : '')
                . '. Respons tanpa data — kemungkinan Worker belum memuat endpoint tersebut.'
            );
        }

        return $data;
    }

    protected function httpOptions(): array
    {
        $caCert = trim((string) config('eduxid.ca_cert', ''));

        return $caCert !== '' && is_file($caCert)
            ? ['verify' => $caCert]
            : [];
    }
}
<?php

namespace App\Http\Controllers;


use App\Services\AcademicPeriodService;
use App\Services\Sync\MasterRegistry;
use App\Services\Broadcast\BroadcastInboxService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\View\View;
use Throwable;

class DashboardController extends Controller
{
    /**
     * ============================================================
     * DASHBOARD UNIVERSAL
     * ============================================================
     *
     *   - siswa                  -> /siswa/lms  (LMS Siswa)
     *   - guru/wali_kelas/bk     -> /dashboard  (dashboard GTK)
     *   - admin/kepala_sekolah   -> /dashboard  (dashboard sekolah)
     *   - tendik                 -> /dashboard  (dashboard TU)
     *
     * [SETUP-MODE-UNIFIED]
     * Definisi setup mode kini SERAGAM untuk guru DAN tendik:
     *
     *     "akun belum terhubung dengan data GTK di tabel teachers
     *      (nip / nik / nuptk = login_id, deleted_at IS NULL)"
     *
     * Karena itu nav (navigation.blade.php) harus memakai logika
     * yang sama persis, agar menu dan dashboard tidak pernah
     * berbeda keputusan.
     *
     * [FIX-SEMESTER] Semester aktif kini DARI DATA (fallback rombel
     * reguler tertinggi) - dulu session kosong membuat dasbor
     * non-guru menampilkan 0 rombel/0 siswa padahal data ada.
     *
     * [SYNC-HEALTH] Dashboard admin kini menampilkan status
     * sinkronisasi: versi master (terpasang/ter-push), heartbeat
     * daemon, dan jumlah antrean tertunda.
     */

    public function index(): View|RedirectResponse
    {
        $user = Auth::user();

        /*
         * ========================================================
         * GUARD SESI
         * ========================================================
         */
        if (!$user) {
            return redirect()->route('login');
        }

        /*
         * ========================================================
         * SISWA - ARAHKAN KE LMS
         * ========================================================
         * Harus di paling atas: sebelum stat apa pun dihitung.
         */
        if (strtolower((string) $user->role) === 'siswa') {
            return redirect()->route('siswa.lms.index');
        }

        /*
         * ========================================================
         * SEMESTER AKTIF - [FIX-SEMESTER] data-driven
         * ========================================================
         */
        $semester_id = $this->resolveActiveSemester();

        /*
         * ========================================================
         * HAK AKSES SINKRONISASI DAPODIK
         * ========================================================
         */
        $canSyncDapodik = strtolower((string) $user->role) === 'admin';

        /*
         * ========================================================
         * STATISTIK DEFAULT
         * ========================================================
         */
        $stats = [
            'setup_mode' => false,

            'sekolah' => null,

            'tahunAjaran' => '-',
            'semester' => '-',

            'is_synced' => false,

            'totalGuru' => 0,
            'totalSiswa' => 0,
            'totalRombel' => 0,
            'totalMapel' => 0,

            'jumlah_kelas' => 0,
            'jumlah_mapel' => 0,
            'jumlah_siswa' => 0,

            'is_online' => false,

            'server_ip' => '127.0.0.1',

            'can_sync_dapodik' => $canSyncDapodik,
        ];

        /*
         * ========================================================
         * [G1-FIX] STATUS CLOUD UNTUK ADMIN
         * ========================================================
         */
        if ($canSyncDapodik) {
            $stats['cloudStatus'] = $this->resolveCloudStatus();
        }

        /*
         * ========================================================
         * [SYNC-HEALTH] STATUS SINKRONISASI UNTUK ADMIN
         * ========================================================
         * Versi master, heartbeat daemon, dan antrean tertunda -
         * angka yang dulu hanya terlihat lewat CLI eduxid:doctor.
         */
        if ($canSyncDapodik) {
            $stats['syncHealth'] = $this->resolveSyncHealth();
        }

        /*
         * ========================================================
         * [PAIRING-FIX] STATUS PAIRING - SEMUA ROLE NON-SISWA
         *
         * Dipakai kartu "eduXid Cloud" di: GTK normal, setup guru,
         * dan setup tendik. Dihitung SEBELUM cabang role.
         * ========================================================
         */
        $stats['cloud_pairing'] = $this->resolveCloudPairing($user);

        /*
         * ========================================================
         * DASHBOARD GURU
         * ========================================================
         *
         * CATATAN: isGuru() WAJIB mencakup role
         * 'guru', 'wali_kelas', dan 'guru_bk'. Jika ada role guru
         * yang tidak masuk method ini, ia diproses sebagai
         * non-guru dan tidak pernah mendapat statistik mengajar.
         */
        if ($user->isGuru()) {

            $guru = $user->teacher();

            /*
             * ----------------------------------------------------
             * [SETUP-MODE-UNIFIED] GURU SETUP
             * ----------------------------------------------------
             */
            if (!$guru) {
                $stats['setup_mode'] = true;

                $this->applyNetworkStatus($stats);

                return $this->dashboardWithMessages($stats);
            }

            /*
             * ----------------------------------------------------
             * DATA MENGAJAR GURU
             * ----------------------------------------------------
             */
            $mengajarQuery = DB::table('teaching_assignments')
                ->join('homerooms', 'teaching_assignments.homeroom_id', '=', 'homerooms.id')
                ->where('teaching_assignments.teacher_id', $guru->id)
                ->where('homerooms.semester_id', $semester_id);

            $stats['jumlah_kelas'] =
                $mengajarQuery
                    ->distinct()
                    ->count('teaching_assignments.homeroom_id');

            $stats['jumlah_mapel'] =
                $mengajarQuery
                    ->distinct()
                    ->count('teaching_assignments.subject_id');

            $homeroomIds =
                $mengajarQuery
                    ->pluck('teaching_assignments.homeroom_id');

            $stats['jumlah_siswa'] =
                DB::table('enrollments')
                    ->whereIn('homeroom_id', $homeroomIds)
                    ->distinct()
                    ->count('student_id');
        }

        /*
         * ========================================================
         * DASHBOARD NON-GURU
         *
         * Termasuk: Admin, Kepala Sekolah, Tendik.
         * (Siswa sudah di-redirect di atas.)
         * ========================================================
         */
        else {

            /*
             * ----------------------------------------------------
             * [SETUP-MODE-UNIFIED] TENDIK SETUP
             *
             * Definisi SAMA dengan guru: setup bila akun belum
             * terhubung dengan data GTK di tabel teachers.
             *
             * Early-return - simetris dengan jalur guru, query
             * master (sekolah/rombel/siswa/mapel) tidak dieksekusi
             * sia-sia saat setup.
             *
             * Catatan konsistensi: navigation.blade.php memakai
             * logika identik (tendikTerdaftar) untuk menyembunyikan
             * menu operasional. Jangan ubah salah satunya saja.
             * ----------------------------------------------------
             */
            if (
                strtolower((string) $user->role) === 'tendik'
                && !$this->gtkTerdaftar($user)
            ) {
                $stats['setup_mode'] = true;

                $this->applyNetworkStatus($stats);

                return $this->dashboardWithMessages($stats);
            }

            /*
             * ----------------------------------------------------
             * DATA SEKOLAH
             * ----------------------------------------------------
             */
            $stats['sekolah'] = DB::table('schools')->first();

            /*
             * ----------------------------------------------------
             * TOTAL GTK AKTIF (SoftDeletes dihormati)
             * ----------------------------------------------------
             */
            $stats['totalGuru'] =
                DB::table('teachers')
                    ->whereNull('deleted_at')
                    ->count();

            /*
             * ----------------------------------------------------
             * DATA ROMBEL
             * ----------------------------------------------------
             */
            $rombelQuery = DB::table('homerooms')
                ->where('semester_id', $semester_id)
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%');

            $stats['totalRombel'] = $rombelQuery->count();
            $rombelIds = $rombelQuery->pluck('id');

            /*
             * ----------------------------------------------------
             * TOTAL SISWA
             * ----------------------------------------------------
             */
            $stats['totalSiswa'] =
                DB::table('enrollments')
                    ->whereIn('homeroom_id', $rombelIds)
                    ->distinct()
                    ->count('student_id');

            /*
             * ----------------------------------------------------
             * TOTAL MATA PELAJARAN
             * ----------------------------------------------------
             */
            $stats['totalMapel'] = DB::table('subjects')->count();

            /*
             * ----------------------------------------------------
             * STATUS SINKRONISASI
             *
             * Indikator sederhana: data sekolah tersedia + ada siswa.
             * Dipakai banner Admin "Belum ada Data Pokok" dan
             * tombol ekspor master. TIDAK dipakai lagi untuk
             * menentukan setup mode tendik.
             * ----------------------------------------------------
             */
            $stats['is_synced'] =
                $stats['sekolah'] !== null
                && $stats['totalSiswa'] > 0;

            /*
             * ----------------------------------------------------
             * TAHUN AJARAN
             *
             * Format semester_id: 20261 = 2025/2026 Ganjil.
             * ----------------------------------------------------
             */
            if ($semester_id && strlen((string) $semester_id) === 5) {
                $tahun1 = substr((string) $semester_id, 0, 4);

                $stats['tahunAjaran'] =
                    $tahun1 . '/' . ((int) $tahun1 + 1);

                $stats['semester'] =
                    substr((string) $semester_id, 4, 1) === '1'
                        ? 'Ganjil'
                        : 'Genap';
            }
        }

        /*
         * ========================================================
         * STATUS JARINGAN + IP SERVER
         * ========================================================
         */
        $this->applyNetworkStatus($stats);

        return $this->dashboardWithMessages($stats);
    }

    /**
     * Muat pesan dari Cloud saat Dasbor pertama dibuka, SEBELUM Blade dirender.
     * Polling JavaScript tetap aktif untuk pesan yang masuk setelah halaman terbuka.
     * Batasi timeout agar Dasbor tidak bergantung pada ketersediaan internet.
     */
    private function dashboardWithMessages(array $stats): View
    {
        $broadcastStatus = null;
        $inbox = app(BroadcastInboxService::class);
        if ($inbox->allowed(Auth::user())) {
            $broadcastStatus = $inbox->refresh(request(), 3);
        }
        return view('dashboard', compact('stats', 'broadcastStatus'));
    }

    /* ============================================================
     * [FIX-SEMESTER] RESOLVER SEMESTER AKTIF
     *
     * Session dulu; kosong -> dari data (rombel reguler tertinggi);
     * data kosong -> '20261' (fallback terakhir, konsisten dengan
     * RaporController).
     * ============================================================ */
    private function resolveActiveSemester(): ?string
    {
        return AcademicPeriodService::selectedOrLatest();
    }

    /* ============================================================
     * [SETUP-MODE-UNIFIED] CEK GTK TERDAFTAR
     *
     * Sumber kebenaran setup mode untuk guru DAN tendik.
     * Harus identik dengan:
     *   - $user->teacher() di User model (SoftDeletes, jadi
     *     whereNull('deleted_at') wajib ada di sini), dan
     *   - pengecekan $isSetupMode di navigation.blade.php.
     *
     * Dipisah menjadi method agar tidak ada dua versi logika
     * di dalam controller ini.
     * ============================================================ */
    private function gtkTerdaftar($user): bool
    {
        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return false;
        }

        return DB::table('teachers')
            ->whereNull('deleted_at')
            ->where(function ($query) use ($loginId) {
                $query->where('nip', $loginId)
                    ->orWhere('nik', $loginId)
                    ->orWhere('nuptk', $loginId);
            })
            ->exists();
    }

    /* ============================================================
     * [PAIRING-FIX] RESOLVER STATUS PAIRING - SEMUA NON-SISWA
     *
     * Device ID: {NPSN}-{login_id}-PC - identik dengan
     * LmsSyncService::getDeviceId(), LmsCourseController@index,
     * dan PengaturanController::indexGuru.
     * ============================================================ */
    private function resolveCloudPairing($user): array
    {
        $result = [
            'npsn' => trim((string) DB::table('schools')->value('npsn')),
            'is_paired' => false,
            'device_id' => null,
        ];

        if ($user instanceof \App\Models\User) {
            $device = app(\App\Services\Sync\GtkPairedDeviceV21::class)->resolve($user);
            if ($device !== null) {
                $result['is_paired'] = true;
                $result['device_id'] = (string) $device->device_id;
            }
        }

        return $result;
    }

    /* ============================================================
     * [SYNC-HEALTH] STATUS SINKRONISASI UNTUK DASHBOARD ADMIN
     *
     * Mengagregasi angka yang dulu hanya terlihat lewat CLI
     * eduxid:doctor: versi master, heartbeat daemon, dan antrean.
     * Semua dibungkus try-catch - dashboard tidak boleh mati
     * karena status.
     * ============================================================ */
    private function resolveSyncHealth(): array
    {
        $health = [
            'master_applied' => 0,
            'master_pushed' => 0,
            'daemon_alive' => false,
            'daemon_heartbeat' => null,
            'outbox_pending' => 0,
            'dead_letters' => 0,
        ];

        try {
            $health['master_applied'] = MasterRegistry::appliedVersion();
            $health['master_pushed']  = MasterRegistry::pushedVersion();
        } catch (Throwable) {
        }

        try {
            $hb = MasterRegistry::get('daemon::heartbeat');

            if ($hb !== null) {
                $age = now()->timestamp - strtotime($hb);

                $health['daemon_heartbeat'] = $hb;
                $health['daemon_alive'] = $age <= 300;
            }
        } catch (Throwable) {
        }

        try {
            $health['outbox_pending'] = DB::table('lms_sync_records')
                ->whereIn('status', ['pending', 'failed'])
                ->count();

            $health['dead_letters'] = DB::table('sync_dead_letters')
                ->where('status', 'dead')
                ->count();
        } catch (Throwable) {
        }

        return $health;
    }

    /* ============================================================
     * [G1-FIX] RESOLVER STATUS SETUP CLOUD (ADMIN)
     * ============================================================ */
    private function resolveCloudStatus(): array
    {
        $result = [
            'ready' => false,
            'token_preview' => null,
            'token_source' => '-',
        ];

        try {
            $configClass = \App\Services\Sync\EduxidCloudConfig::class;

            if (
                class_exists($configClass)
                && method_exists($configClass, 'adminToken')
                && method_exists($configClass, 'syncServer')
            ) {
                $token = trim((string) $configClass::adminToken());
                $syncServer = trim((string) $configClass::syncServer());

                if ($token !== '' && $syncServer !== '') {
                    $result['ready'] = true;
                    $result['token_preview'] =
                        substr($token, 0, 8) . (strlen($token) > 8 ? '...' : '');
                    $result['token_source'] = 'Setup Cloud';
                }
            }
        } catch (Throwable) {
            // Biarkan default - kartu menampilkan "BELUM TERKONFIGURASI".
        }

        return $result;
    }

    /* ============================================================
     * STATUS JARINGAN + DETEKSI IP SERVER LOKAL
     *
     * [PING-FIX] URL ping: /api/local/ping (route api.local.ping).
     * ============================================================ */
    private function applyNetworkStatus(array &$stats): void
    {
        /*
         * --------------------------------------------------------
         * CEK STATUS KONEKSI SERVER
         * --------------------------------------------------------
         */
        $currentHost = request()->getHost();
        $isOnline = false;

        /*
         * AKSES LOCALHOST - komputer Admin/Server atau Guru lokal.
         */
        if (in_array($currentHost, ['localhost', '127.0.0.1', '0.0.0.0'], true)) {

            $serverIpSetting =
                DB::table('app_settings')
                    ->where('key', 'server_ip')
                    ->value('value');

            if ($serverIpSetting) {
                try {
                    $response =
                        Http::timeout(2)
                            ->get('http://' . $serverIpSetting . ':8000/api/local/ping');

                    $isOnline = $response->successful();
                } catch (Throwable) {
                    $isOnline = false;
                }
            } else {
                $isOnline = true;
            }
        }
        /*
         * AKSES MELALUI IP SERVER - browser mengakses server langsung.
         */
        else {
            $isOnline = true;
        }

        $stats['is_online'] = $isOnline;

        /*
         * --------------------------------------------------------
         * DETEKSI IP SERVER LOKAL
         * --------------------------------------------------------
         */
        $serverIp = '127.0.0.1';

        /*
         * METODE 1 - SOCKET
         */
        if (function_exists('socket_create')) {

            $sock = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

            if ($sock !== false) {

                @socket_connect($sock, '8.8.8.8', 53);

                $localIp = null;

                @socket_getsockname($sock, $localIp);
                @socket_close($sock);

                /*
                 * Jangan gunakan IP virtual.
                 */
                if (
                    isset($localIp)
                    && filter_var($localIp, FILTER_VALIDATE_IP)
                    && $localIp !== '0.0.0.0'
                    && strpos($localIp, '172.22.') === false
                    && strpos($localIp, '192.168.56.') === false
                ) {
                    $serverIp = $localIp;
                }
            }
        }

        /*
         * METODE 2 - IPCONFIG WINDOWS
         */
        if ($serverIp === '127.0.0.1' && PHP_OS_FAMILY === 'Windows') {

            $output = [];
            @exec('ipconfig', $output);

            $currentAdapter = '';
            $foundWifiIp = null;
            $foundEthernetIp = null;

            foreach ($output as $line) {

                if (stripos($line, 'adapter') !== false) {
                    $currentAdapter = $line;
                }

                if (stripos($line, 'IPv4') !== false) {

                    if (preg_match('/(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/', $line, $ipMatches)) {

                        $ip = $ipMatches[1];

                        if (
                            strpos($ip, '192.168.56.') !== false
                            || strpos($ip, '172.22.') !== false
                        ) {
                            continue;
                        }

                        $isVirtual =
                            stripos($currentAdapter, 'vEthernet') !== false
                            || stripos($currentAdapter, 'Hyper-V') !== false
                            || stripos($currentAdapter, 'VirtualBox') !== false
                            || stripos($currentAdapter, 'VMware') !== false;

                        if ($isVirtual) {
                            continue;
                        }

                        /*
                         * Prioritas Wi-Fi.
                         */
                        if (
                            stripos($currentAdapter, 'Wi-Fi') !== false
                            || stripos($currentAdapter, 'Wireless') !== false
                        ) {
                            $foundWifiIp = $ip;
                            break;
                        }

                        /*
                         * Ethernet sebagai cadangan.
                         */
                        if (
                            stripos($currentAdapter, 'Ethernet') !== false
                            && $foundEthernetIp === null
                        ) {
                            $foundEthernetIp = $ip;
                        }
                    }
                }
            }

            if ($foundWifiIp !== null) {
                $serverIp = $foundWifiIp;
            } elseif ($foundEthernetIp !== null) {
                $serverIp = $foundEthernetIp;
            }
        }

        $stats['server_ip'] = $serverIp;
    }
}
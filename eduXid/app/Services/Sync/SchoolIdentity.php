<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ============================================================
 * [REWRITE-01] SCHOOL IDENTITY — SATU SUMBER IDENTITAS SEKOLAH
 * ============================================================
 *
 * Menggantikan seluruh logika NPSN tersebar di LmsSyncService:
 *
 *   - npsnFromTable()         → candidateRows()
 *   - npsnAutoDiscover()      → DIHAPUS (pemindaian tabel acak +
 *                               static cache lintas-request = sumber
 *                               identitas salah yang senyap)
 *   - npsnFromSettings()      → DIHAPUS (sumber kedua yang bisa
 *                               menyimpang dari schools)
 *   - persistNpsnIfPossible() → DIHAPUS (menulis baris schools
 *                               palsu id=1 — menciptakan sekolah
 *                               ganda saat dapodik:sync masuk)
 *
 * Prinsip:
 *   1. HANYA tabel schools sumber kebenaran.
 *   2. SELALU baca segar dari DB (tanpa cache statis/proses) —
 *      hasil dapodik:sync langsung terlihat oleh daemon dan FPM
 *      tanpa restart.
 *   3. AMBIGU = GAGAL KERAS. Lebih baik sync berhenti dengan pesan
 *      jelas daripada memakai NPSN salah yang mencemari device ID,
 *      password baseline, dan routing event.
 *   4. TANPA EFEK SAMPING — kelas ini tidak pernah menulis.
 *
 * Kontrak device ID legacy khusus saat protocol=legacy:
 *   {NPSN}-{login_id}-PC/HP
 * Kontrak v21-opaque WAJIB memakai registry alias per role:
 *   {NPSN}-t_<alias>-PC/HP atau {NPSN}-s_<alias>-HP.
 * Tidak ada fallback ke identitas asli ketika v21-opaque aktif.
 * ============================================================
 */
final class SchoolIdentity
{
    /**
     * NPSN sekolah — dipakai sebagai school_id di seluruh sistem.
     *
     * @throws SchoolIdentityException bila belum tersedia, ambigu,
     *         atau format tidak valid.
     */
    public function schoolId(): string
    {
        $rows = $this->candidateRows();

        if ($rows === []) {
            throw new SchoolIdentityException(
                'NPSN sekolah belum tersedia di tabel schools. '
                . 'Jalankan sinkronisasi Dapodik atau impor '
                . 'Template_Impor_Sekolah.xlsx terlebih dahulu.'
            );
        }

        $distinct = array_values(array_unique(array_map(
            static fn (object $row): string => trim((string) $row->npsn),
            $rows
        )));

        if (count($distinct) > 1) {
            throw new SchoolIdentityException(
                'Tabel schools berisi lebih dari satu NPSN berbeda — '
                . 'identitas sinkronisasi AMBIGU. Sisakan satu baris sekolah. '
                . 'Baris terdeteksi: ' . $this->describeRows($rows)
            );
        }

        $npsn = $distinct[0];

        if (!ctype_digit($npsn) || strlen($npsn) < 8) {
            throw new SchoolIdentityException(
                "NPSN di tabel schools tidak valid ('{$npsn}') — "
                . 'wajib angka minimal 8 digit. '
                . 'Perbaiki baris sekolah sebelum sinkronisasi dijalankan.'
            );
        }

        return $npsn;
    }

    /** Alias semantik. */
    public function npsn(): string
    {
        return $this->schoolId();
    }

    /**
     * Status untuk panel diagnostik admin — tidak pernah throw.
     */
    public function status(): array
    {
        try {
            $rows = $this->candidateRows();

            return [
                'ready'     => true,
                'school_id' => $this->schoolId(),
                'issue'     => null,
                'rows'      => count($rows),
            ];
        } catch (Throwable $e) {
            $rowCount = 0;

            try {
                $rowCount = count($this->candidateRows());
            } catch (Throwable) {
            }

            return [
                'ready'     => false,
                'school_id' => null,
                'issue'     => $e->getMessage(),
                'rows'      => $rowCount,
            ];
        }
    }

    /**
     * Device ID guru/siswa.
     *
     * Segmen login dilarang mengandung '-' karena seluruh sistem
     * mem-parsing device ID dengan explode('-'): segmen ber '-' sudah
     * terbukti memutus owner-check attendance dan authorizeDeviceAccess
     * secara senyap. Gagal keras di sini membuat masalah login_id
     * terlihat sejak pembentukan device ID — bukan sebagai kegagalan
     * sinkron yang tak berujung.
     */
    public function deviceId(string $loginId, string $suffix): string
    {
        if (OpaqueRouteRegistryV21::enabled()) {
            throw new SchoolIdentityException('BLOCKED V21: role wajib eksplisit; gunakan teacherPcDeviceId, teacherHpDeviceId, atau studentDeviceId.');
        }
        $school = $this->schoolId();

        $login = trim($loginId);

        if ($login === '') {
            throw new SchoolIdentityException(
                'Login ID tidak boleh kosong saat membentuk device ID.'
            );
        }

        if (preg_match('/^[A-Za-z0-9._]+$/', $login) !== 1) {
            throw new SchoolIdentityException(
                "Login ID '{$login}' mengandung karakter tidak aman untuk "
                . 'device ID (hanya huruf, angka, titik, underscore — tanpa '
                . 'tanda hubung). Perbaiki login_id akun terkait di tabel users.'
            );
        }

        if (!in_array($suffix, ['HP', 'PC'], true)) {
            throw new SchoolIdentityException(
                "Suffix device ID tidak valid: '{$suffix}'."
            );
        }

        return $school . '-' . $login . '-' . $suffix;
    }

    public function teacherPcDeviceId(string $loginId): string
    {
        if (OpaqueRouteRegistryV21::enabled()) {
            return app(OpaqueRouteRegistryV21::class)->deviceId($this->schoolId(), 'teacher', $loginId, 'PC');
        }
        return $this->deviceId($loginId, 'PC');
    }

    public function teacherHpDeviceId(string $loginId): string
    {
        if (OpaqueRouteRegistryV21::enabled()) {
            return app(OpaqueRouteRegistryV21::class)->deviceId($this->schoolId(), 'teacher', $loginId, 'HP');
        }
        return $this->deviceId($loginId, 'HP');
    }

    public function studentDeviceId(string $nisn): string
    {
        if (OpaqueRouteRegistryV21::enabled()) {
            return app(OpaqueRouteRegistryV21::class)->deviceId($this->schoolId(), 'student', $nisn, 'HP');
        }
        return $this->deviceId($nisn, 'HP');
    }

    /* ============================================================
     * INTERNAL
     * ============================================================ */

    /**
     * Baris sekolah kandidat — SEGAR setiap pemanggilan.
     * Sengaja tanpa cache: satu query murah, dan hasil
     * dapodik:sync harus langsung terlihat.
     *
     * Exception DB dibiarkan naik apa adanya agar pemanggil
     * membedakan "belum dikonfigurasi" dari "DB down".
     *
     * @return object[]
     */
    protected function candidateRows(): array
    {
        if (!Schema::hasTable('schools')) {
            return [];
        }

        return DB::table('schools')
            ->whereNotNull('npsn')
            ->where('npsn', '!=', '')
            ->orderBy('id')
            ->get(['id', 'nama', 'npsn'])
            ->all();
    }

    protected function describeRows(array $rows): string
    {
        return implode('; ', array_map(
            static fn (object $row): string => sprintf(
                '#%d "%s" NPSN=%s',
                $row->id,
                $row->nama ?? '?',
                $row->npsn
            ),
            $rows
        ));
    }
}
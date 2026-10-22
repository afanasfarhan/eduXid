<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ============================================================
 * [FASE-0 / P4 / R-04 / HR-01] SEMESTER SERVICE
 * ============================================================
 * Menggantikan 5 salinan getActiveSemester()/resolveViewingSemester
 * (NilaiController, RaporController, HomeroomController,
 * WaliKelasController, EkskulController, KokurikulerController)
 * yang fallback-nya saling menyimpang ('20261' hardcoded vs dari
 * data vs ?string).
 *
 * Sumber: session('semester_aktif') → max(semester_id) rombel
 * reguler → NULL. Pemanggil melewatkan guard bila NULL (semantik
 * NilaiController asli — bukan konstanta palsu).
 * ============================================================
 */
final class SemesterService
{
    public function active(): ?string
    {
        $aktif = session('semester_aktif');

        if (is_string($aktif) && trim($aktif) !== '') {
            return trim($aktif);
        }

        try {
            $max = DB::table('homerooms')
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%')
                ->max('semester_id');

            return $max !== null ? (string) $max : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Semester yang dilihat: ?semester= (5 digit, tidak melewati
     * aktif) atau semester aktif. Bila semester aktif tak diketahui,
     * param valid tetap diterima (akses historis).
     */
    public function viewing(Request $request): ?string
    {
        $aktif = $this->active();
        $requested = trim((string) $request->query('semester', ''));

        if (preg_match('/^\d{5}$/', $requested) === 1) {
            if ($aktif === null || $requested <= $aktif) {
                return $requested;
            }
        }

        return $aktif;
    }

    /* ---------- pintu statis untuk drop-in controller ---------- */

    public static function activeSemester(): ?string
    {
        return app(self::class)->active();
    }

    public static function viewingSemester(Request $request): ?string
    {
        return app(self::class)->viewing($request);
    }
}
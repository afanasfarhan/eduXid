<?php

declare(strict_types=1);

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R10 STAGING: trusted local LJK scan identity lookup only.
 * It does NOT authorize Cloud upload, score publication, student roster access,
 * or a PWA scan event. No raw NISN is returned to the Cloud caller.
 */
final class CloudLjkLocalReconciliationV21
{
    /** @return array{student_id:int,student_alias:string} */
    public function resolveLocalScan(string $recognizedNisn, int $homeroomId): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) {
            throw new RuntimeException('BLOCKED V21: verifikasi LJK anonim hanya di lingkungan staging.');
        }
        $nisn = trim($recognizedNisn);
        if (!preg_match('/^[0-9]{10}$/D', $nisn) || $homeroomId <= 0) {
            throw new RuntimeException('BLOCKED V21: NISN pindai/rombel LJK tidak valid.');
        }
        if (DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
            throw new RuntimeException('BLOCKED V21: identitas LJK tidak unik di sekolah.');
        }
        $studentId = (int) (DB::table('students')->where('nisn', $nisn)->value('id') ?? 0);
        if ($studentId < 1 || !DB::table('enrollments')->where('homeroom_id', $homeroomId)
            ->where('student_id', $studentId)->exists()) {
            throw new RuntimeException('BLOCKED V21: pemilik LJK tidak terdaftar pada rombel.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        $alias = app(OpaqueRouteRegistryV21::class)->alias($school, 'student', $nisn);
        if (!OpaqueIdentityV21::isAlias($alias, 'student')
            || !hash_equals($nisn, app(CloudInboundStudentV21::class)->resolveInHomeroom($alias, $homeroomId))) {
            throw new RuntimeException('BLOCKED V21: registrasi alias dan LJK tidak konsisten.');
        }
        // No 'nisn' field is returned; LJK sheets stay on the local device.
        return ['student_id' => $studentId, 'student_alias' => $alias];
    }
}

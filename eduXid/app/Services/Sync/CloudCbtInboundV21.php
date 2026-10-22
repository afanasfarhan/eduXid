<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Exam;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * V21R8 staging — alias siswa dari CBT Cloud hanya di-resolve pada GTK.
 * Sumber wajib perangkat siswa yang sama; scope guru-mapel-rombel harus
 * berasal dari data teaching_assignments lokal, tidak dari payload PWA.
 */
final class CloudCbtInboundV21
{
    public static function checkAliasSource(string $school, string $alias, string $source): void
    {
        if (!OpaqueIdentityV21::isAlias($alias, 'student') ||
            $source !== $school . '-' . $alias . '-HP') {
            throw new RuntimeException('BLOCKED V21: sumber CBT dan alias siswa tidak cocok.');
        }
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function localize(array $payload, string $source): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return $payload;
        $school = app(SchoolIdentity::class)->schoolId();
        $alias = trim((string) ($payload['student_login_id'] ?? ''));
        self::checkAliasSource($school, $alias, $source);
        if (!empty($payload['nisn']) || !empty($payload['student_nisn']) ||
            !empty($payload['student_nik']) || !empty($payload['student_name'])) {
            throw new RuntimeException('BLOCKED V21: CBT Cloud tidak boleh membawa identitas siswa mentah.');
        }
        if (isset($payload['student_id']) && $payload['student_id'] !== $alias) {
            throw new RuntimeException('BLOCKED V21: student_id CBT tidak cocok dengan identitas pengirim.');
        }
        $examSync = trim((string) ($payload['exam_sync_id'] ?? ''));
        $exam = $examSync !== '' ? Exam::query()->where('sync_id', $examSync)->first() : null;
        if (!$exam || (int) $exam->teacher_id <= 0 || (int) $exam->subject_id <= 0) {
            throw new RuntimeException('BLOCKED V21: Paket CBT tidak ditemukan untuk validasi guru/mapel.');
        }
        // Jangan mempercayai homeroom_id atau teacher_id dari payload Cloud.
        $rooms = DB::table('teaching_assignments')
            ->where('teacher_id', (int) $exam->teacher_id)
            ->where('subject_id', (int) $exam->subject_id)
            ->whereNotNull('homeroom_id')
            ->distinct()->limit(501)->pluck('homeroom_id');
        if ($rooms->isEmpty() || $rooms->count() > 500) {
            throw new RuntimeException('BLOCKED V21: rombel yang berhak untuk CBT belum dapat ditentukan.');
        }
        $matches = [];
        $resolver = app(CloudInboundStudentV21::class);
        foreach ($rooms as $room) {
            try {
                $nisn = $resolver->resolveInHomeroom($alias, (int) $room);
                $matches[$nisn] = true;
            } catch (RuntimeException $e) {
                // Rombel yang tidak memiliki alias tersebut bukan bukti kegagalan
                // selama masih ada satu siswa unik dalam rombel lainnya.
                // Kesalahan data ambigu dicegah melalui pemeriksaan unik di bawah.
            }
        }
        if (count($matches) !== 1) {
            throw new RuntimeException('BLOCKED V21: alias CBT tidak cocok unik dalam penugasan guru.');
        }
        $nisn = (string) array_key_first($matches);
        if (DB::table('students')->where('nisn', $nisn)->limit(2)->count() !== 1) {
            throw new RuntimeException('BLOCKED V21: identitas siswa CBT ambigu di database lokal.');
        }
        // HANYA salinan apply lokal yang memperoleh NISN; record ledger tetap
        // menerima payload Cloud asli yang berisi alias.
        $payload['student_login_id'] = $nisn;
        $payload['student_id'] = $nisn;
        return $payload;
    }
}

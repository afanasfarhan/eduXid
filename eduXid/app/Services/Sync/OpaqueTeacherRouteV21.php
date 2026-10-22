<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\Teacher;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Bind an incoming alias device to the LOCAL authoritative teacher record. */
final class OpaqueTeacherRouteV21
{
    public function aliasForTeacher(int $teacherId): string
    {
        if (!OpaqueRouteRegistryV21::enabled() || $teacherId <= 0) {
            throw new RuntimeException('BLOCKED V21: identitas pemilik guru tidak valid.');
        }
        $teacher = Teacher::query()->find($teacherId);
        if (!$teacher) throw new RuntimeException('BLOCKED V21: pemilik penugasan tidak ditemukan.');
        $school = app(SchoolIdentity::class)->schoolId();
        $candidates = [];
        if ((int) ($teacher->user_id ?? 0) > 0) {
            $candidate = DB::table('users')->where('id', (int) $teacher->user_id)->value('login_id');
            if (trim((string) $candidate) !== '') $candidates[] = trim((string) $candidate);
        }
        foreach (['nip','nik','nuptk'] as $key) {
            $candidate = trim((string) ($teacher->{$key} ?? ''));
            if ($candidate !== '') $candidates[] = $candidate;
        }
        $aliases = [];
        foreach (array_unique($candidates) as $candidate) {
            try {
                $alias = app(OpaqueRouteRegistryV21::class)->alias($school, 'teacher', $candidate);
                $aliases[$alias] = true;
            } catch (RuntimeException $error) {
                // Individual alternate identifiers need not be provisioned.
                // A missing primary binding is detected by the final count.
                if (!str_contains($error->getMessage(), 'belum diprovisioning')) throw $error;
            }
        }
        if (count($aliases) !== 1) {
            throw new RuntimeException('BLOCKED V21: pemilik guru tidak mempunyai tepat satu alias terverifikasi.');
        }
        return (string) array_key_first($aliases);
    }

    /** Reverse resolution never consults Cloud to recover NIP/NIK; only local proofs. */
    public function teacherForAlias(string $alias): Teacher
    {
        if (!OpaqueIdentityV21::isAlias($alias, 'teacher')) {
            throw new RuntimeException('BLOCKED V21: alias pengirim tidak valid.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        $binding = DB::table('cloud_identity_alias_routes')
            ->where('school_id', $school)->where('role', 'teacher')
            ->where('cloud_alias', $alias)->value('local_digest');
        if (!is_string($binding) || !preg_match('/^[a-f0-9]{64}$/D', $binding)) {
            throw new RuntimeException('BLOCKED V21: alias pengirim belum dibuktikan lokal.');
        }
        $teachers = Teacher::query()->orderBy('id')->limit(2001)->get();
        if ($teachers->count() > 2000) {
            throw new RuntimeException('BLOCKED V21: kandidat guru melebihi batas verifikasi.');
        }
        $matched = [];
        foreach ($teachers as $teacher) {
            $candidates = [];
            if ((int) ($teacher->user_id ?? 0) > 0) {
                $login = DB::table('users')->where('id', (int) $teacher->user_id)->value('login_id');
                if (trim((string) $login) !== '') $candidates[] = trim((string) $login);
            }
            foreach (['nip', 'nik', 'nuptk'] as $column) {
                $id = trim((string) ($teacher->{$column} ?? ''));
                if ($id !== '') $candidates[] = $id;
            }
            foreach (array_unique($candidates) as $candidate) {
                $digest = OpaqueRouteRegistryV21::lookupDigest($school, 'teacher', $candidate);
                if (hash_equals($binding, $digest)) {
                    $matched[(int) $teacher->id] = $teacher;
                    break;
                }
            }
        }
        if (count($matched) !== 1) {
            throw new RuntimeException('BLOCKED V21: alias guru tidak unik pada data lokal.');
        }
        $teacher = reset($matched);
        if (!hash_equals($alias, $this->aliasForTeacher((int) $teacher->id))) {
            throw new RuntimeException('BLOCKED V21: bukti kepemilikan guru tidak konsisten.');
        }
        return $teacher;
    }

    public function assertDeviceOwnedByTeacher(string $device, int $teacherId, string $suffix): void
    {
        if (!in_array($suffix, ['PC', 'HP'], true)) {
            throw new RuntimeException('BLOCKED V21: jenis perangkat tidak sah.');
        }
        $school = app(SchoolIdentity::class)->schoolId();
        $alias = $this->aliasForTeacher($teacherId);
        if (!hash_equals($school . '-' . $alias . '-' . $suffix, $device)) {
            throw new RuntimeException('BLOCKED V21: perangkat bukan milik pengajar/pemilik ujian.');
        }
    }
}

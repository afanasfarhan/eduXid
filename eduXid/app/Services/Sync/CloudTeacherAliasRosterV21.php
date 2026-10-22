<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Models\SyncDevice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Reconcile already-paired GTK against Admin-issued Cloud teacher roster. */
final class CloudTeacherAliasRosterV21
{
    public function reconcile(?string $expectedDeviceId = null): array
    {
        if (!OpaqueRouteRegistryV21::enabled()) return ['skipped' => 'legacy'];
        $school = app(SchoolIdentity::class)->schoolId();
        foreach (['teachers','teaching_assignments','homerooms','enrollments','students','cloud_identity_alias_routes'] as $table) {
            if (!Schema::hasTable($table)) throw new RuntimeException('BLOCKED V21: tabel lokal untuk rekonsiliasi roster belum tersedia.');
        }
        $query = SyncDevice::query()->where('school_id', $school)
            ->where('device_type', 'teacher')->where('is_active', true)
            ->whereNotNull('sync_token');
        if ($expectedDeviceId !== null) $query->where('device_id', $expectedDeviceId);
        $device = $query->orderByDesc('last_synced_at')->first();
        if (!$device || !is_string($device->sync_token) || $device->sync_token === '') {
            throw new RuntimeException('BLOCKED V21: laptop GTK belum mempunyai token pairing aktif.');
        }
        $userId = (int) $device->user_id;
        if ($userId <= 0) throw new RuntimeException('BLOCKED V21: user pemilik perangkat GTK belum terikat.');
        $localLogin = trim((string) DB::table('users')->where('id', $userId)->value('login_id'));
        if ($localLogin === '') throw new RuntimeException('BLOCKED V21: login lokal pemilik GTK tidak ditemukan.');
        $pc = app(OpaqueRouteRegistryV21::class)->deviceId($school, 'teacher', $localLogin, 'PC');
        if (!hash_equals($pc, (string) $device->device_id)) {
            throw new RuntimeException('BLOCKED V21: device PC tidak cocok dengan registry guru.');
        }
        $teachers = DB::table('teachers')->where('user_id', $userId)->pluck('id');
        if ($teachers->count() !== 1) throw new RuntimeException('BLOCKED V21: pemilik GTK ambigu pada tabel teachers.');
        $teacherId = (int) $teachers->first();
        $cloud = EduxidCloudConfig::syncServer();
        if (!str_starts_with($cloud, 'https://')) throw new RuntimeException('BLOCKED V21: endpoint Cloud wajib HTTPS.');
        $request = Http::timeout(20)->connectTimeout(5)->withToken((string) $device->sync_token)->acceptJson();
        $ca = trim((string) config('eduxid.ca_cert', ''));
        if ($ca !== '' && is_file($ca)) $request = $request->withOptions(['verify'=>$ca]);
        $response = $request->get(rtrim($cloud, '/') . '/api/teacher/alias-roster-v21');
        if (!$response->successful() || $response->json('success') !== true) {
            $code = trim((string) ($response->json('error.code') ?: 'HTTP_' . $response->status()));
            throw new RuntimeException('BLOCKED V21: roster Cloud belum siap [' . preg_replace('/[^A-Z0-9_]/', '', strtoupper($code)) . '].');
        }
        $data = $response->json('data');
        if (!is_array($data) || !hash_equals($school, (string) ($data['school_id'] ?? ''))
            || !hash_equals(trim(explode('-', $pc)[1] ?? ''), (string) ($data['teacher_alias'] ?? ''))
            || !is_array($data['assignments'] ?? null)) {
            throw new RuntimeException('BLOCKED V21: identitas jawaban roster tidak cocok.');
        }
        $valid = []; $invalid = 0;
        foreach ($data['assignments'] as $assignment) {
            if (!is_array($assignment)) { $invalid++; continue; }
            $homeroomId = (int) ($assignment['homeroom_id'] ?? 0);
            $taId = (int) ($assignment['ta_id'] ?? 0);
            if ($homeroomId <= 0 || !is_array($assignment['roster'] ?? null)) { $invalid++; continue; }
            $authorized = $taId > 0
                ? DB::table('teaching_assignments')->where('id', $taId)->where('teacher_id', $teacherId)
                    ->where('homeroom_id', $homeroomId)->exists()
                : (bool) ($assignment['is_homeroom_teacher'] ?? false) &&
                    DB::table('homerooms')->where('id', $homeroomId)->where('homeroom_teacher_id', $teacherId)->exists();
            if (!$authorized) { $invalid++; continue; }
            foreach ($assignment['roster'] as $item) {
                if (!is_array($item)) { $invalid++; continue; }
                $studentId = (int) ($item['id'] ?? 0);
                $alias = (string) ($item['student_login_id'] ?? '');
                if ($studentId <= 0 || !OpaqueIdentityV21::isAlias($alias, 'student')) { $invalid++; continue; }
                $enrolled = DB::table('enrollments')->where('student_id', $studentId)
                    ->where('homeroom_id', $homeroomId)->exists();
                if (!$enrolled) { $invalid++; continue; }
                $nisn = trim((string) DB::table('students')->where('id', $studentId)->value('nisn'));
                if ($nisn === '') { $invalid++; continue; }
                $key = OpaqueRouteRegistryV21::lookupDigest($school, 'student', $nisn);
                if (isset($valid[$key]) && !hash_equals($valid[$key]['alias'], $alias)) {
                    throw new RuntimeException('BLOCKED V21: alias siswa konflik antar penugasan.');
                }
                $valid[$key] = ['id'=>$nisn, 'alias'=>$alias];
            }
        }
        $routes = app(OpaqueRouteRegistryV21::class);
        foreach ($valid as $item) {
            $routes->provision($school, 'student', $item['id'], $item['alias']);
        }
        return ['mapped'=>count($valid), 'scope_rejected'=>$invalid];
    }
}

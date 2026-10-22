<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Services\Security\InitialCredentialVault;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * V21R80: akun lokal identitas manual; tidak membuat alias baru dan tidak
 * mengirim Cloud dari HTTP request. Publish melalui MasterPushService yang ada.
 */
final class ManualCloudDirectoryAccountsV21R80
{
    private const TEACHER_ROLES = ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah', 'tendik'];

    public function auditTeacher(object $teacher): string
    {
        $id = (int) $teacher->id;
        $nip = trim((string) ($teacher->nip ?? ''));
        $nik = trim((string) ($teacher->nik ?? ''));
        if ($nip === '' && $nik === '') return 'missing_identity';
        foreach (array_unique(array_filter([$nip, $nik])) as $login) {
            if (!preg_match('/^[0-9]{8,20}$/D', $login)) return 'invalid_identity';
            $collidingTeacher = DB::table('teachers')
                ->where('id', '!=', $id)
                ->where(function ($q) use ($login) { $q->where('nip', $login)->orWhere('nik', $login); })
                ->when(Schema::hasColumn('teachers', 'deleted_at'), fn ($q) => $q->whereNull('deleted_at'))
                ->exists();
            if ($collidingTeacher) return 'duplicate_identity';
        }
        $logins = array_values(array_unique(array_filter([$nip, $nik])));
        // A pre-existing NUPTK-only user needs explicit identity reconciliation,
        // not a second login under NIP/NIK for the same GTK.
        $nuptk = trim((string) ($teacher->nuptk ?? ''));
        if ($nuptk !== '' && !in_array($nuptk, $logins, true)
            && DB::table('users')->where('login_id', $nuptk)->exists()) return 'legacy_nuptk_login';
        $users = DB::table('users')->whereIn('login_id', $logins)->get(['id', 'role', 'login_id']);
        if ($users->count() > 1) return 'ambiguous_user';
        if ($users->count() === 1) {
            $user = $users->first();
            return in_array((string) $user->role, self::TEACHER_ROLES, true) ? 'existing' : 'role_collision';
        }
        return 'new';
    }

    public function auditStudent(object $student): string
    {
        $nisn = trim((string) ($student->nisn ?? ''));
        if ($nisn === '') return 'missing_identity';
        if (!preg_match('/^[0-9]{10}$/D', $nisn)) return 'invalid_identity';
        if (DB::table('students')->where('id','!=',(int) $student->id)->where('nisn',$nisn)->exists()) {
            return 'duplicate_identity';
        }
        $user = DB::table('users')->where('login_id', $nisn)->first(['id','role']);
        if (!$user) return 'new';
        return (string) $user->role === 'siswa' ? 'existing' : 'role_collision';
    }

    public function provisionTeacher(int $teacherId): string
    {
        $teacher = DB::table('teachers')->where('id', $teacherId)->first();
        if (!$teacher) throw new RuntimeException('GTK lokal tidak tersedia.');
        $status = $this->auditTeacher($teacher);
        if ($status === 'missing_identity') return 'missing_identity';
        if (!in_array($status, ['new', 'existing'], true)) {
            throw ValidationException::withMessages(['nip' => 'Identitas GTK tidak dapat digunakan untuk akun Cloud ('.$status.'). Periksa NIP/NIK serta akun lokal terkait.']);
        }
        if ($status === 'new') {
            $this->assertVaultReady();
            $nip = trim((string) ($teacher->nip ?? ''));
            $nik = trim((string) ($teacher->nik ?? ''));
            $login = $nip !== '' ? $nip : $nik;
            $jenis = strtolower(trim((string) ($teacher->jenis_ptk ?? '')));
            $jabatan = strtolower(trim((string) ($teacher->jabatan_ptk ?? '')));
            $role = 'tendik';
            if (str_contains($jenis,'guru') || str_contains($jenis,'konselor') || str_contains($jenis,'bk')) $role = 'guru';
            if (str_contains($jenis,'konselor') || str_contains($jenis,'bk') || str_contains($jabatan,'bimbingan konseling')) $role = 'guru_bk';
            if (str_contains($jenis,'kepala sekolah') || str_contains($jabatan,'kepala sekolah')) $role = 'kepala_sekolah';
            $row = [
                'name' => (string) ($teacher->nama ?? 'GTK'),
                'login_id' => $login,
                'email' => $login.'@eduxid.id',
                'role' => $role,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('users', 'email_verified_at')) $row['email_verified_at'] = now();
            InitialCredentialVault::createForNewUser($row);
        }
        $this->markDirty();
        return $status;
    }

    public function provisionStudent(int $studentId): string
    {
        $student = DB::table('students')->where('id', $studentId)->first();
        if (!$student) throw new RuntimeException('Murid lokal tidak tersedia.');
        $status = $this->auditStudent($student);
        if (!in_array($status, ['new','existing'], true)) {
            throw ValidationException::withMessages(['nisn' => 'Identitas murid tidak dapat digunakan untuk akun Cloud ('.$status.'). NISN wajib 10 angka dan unik.']);
        }
        if ($status === 'new') {
            $this->assertVaultReady();
            $login = trim((string) $student->nisn);
            $row = [
                'name' => (string) ($student->nama ?? 'Murid'),
                'login_id' => $login,
                'email' => $login.'@eduxid.id',
                'role' => 'siswa',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('users', 'email_verified_at')) $row['email_verified_at'] = now();
            InitialCredentialVault::createForNewUser($row);
        }
        $this->markDirty();
        return $status;
    }

    /** Tolak perubahan identity aktif; rotasi memerlukan rekonsiliasi alias. */
    public function assertTeacherIdentityNotRotated(int $teacherId, array $fields): void
    {
        $teacher = DB::table('teachers')->where('id', $teacherId)->first();
        if (!$teacher) return;
        $old = array_values(array_filter(array_map(fn ($v) => trim((string) $v), [$teacher->nip ?? null, $teacher->nik ?? null])));
        $currentUser = $old === [] ? null : DB::table('users')->whereIn('login_id', $old)->first(['login_id']);
        $new = array_map(fn ($v) => trim((string) $v), [$fields['nip'] ?? null, $fields['nik'] ?? null]);
        if (!$currentUser) {
            if (array_diff($old, $new) !== []) $this->assertNotPublished($old, 'teacher');
            return;
        }
        if (!in_array((string) $currentUser->login_id, $new, true)) {
            throw ValidationException::withMessages(['nip' => 'Perubahan NIP/NIK yang sudah menjadi identitas akun memerlukan rekonsiliasi. Akun Cloud tidak dirotasi otomatis.']);
        }
    }

    public function assertStudentIdentityNotRotated(int $studentId, string $newNisn): void
    {
        $old = trim((string) DB::table('students')->where('id',$studentId)->value('nisn'));
        if ($old === '' || hash_equals($old, trim($newNisn))) return;
        if (DB::table('users')->where('login_id',$old)->where('role','siswa')->exists()) {
            throw ValidationException::withMessages(['nisn' => 'NISN sudah menjadi identitas akun murid. Perubahan memerlukan rekonsiliasi alias, bukan penerbitan akun baru.']);
        }
        $this->assertNotPublished([$old], 'student');
    }

    private function assertNotPublished(array $ids, string $role): void
    {
        if (!CloudDirectoryAliasV21::stagingEnabled() || !Schema::hasTable('cloud_identity_alias_routes')) return;
        $school = app(SchoolIdentity::class)->schoolId();
        foreach ($ids as $id) {
            $digest = OpaqueRouteRegistryV21::lookupDigest($school, $role, $id);
            if (DB::table('cloud_identity_alias_routes')->where('school_id',$school)
                ->where('role',$role)->where('local_digest',$digest)->exists()) {
                throw ValidationException::withMessages(['nisn' => 'Identitas telah dipublikasikan ke Cloud. Perubahan memerlukan rekonsiliasi akun, bukan penerbitan alias baru.']);
            }
        }
    }

    public function markDirty(): void
    {
        if (!Schema::hasTable('app_settings')) {
            throw new RuntimeException('Tabel app_settings belum tersedia; migrasi Laravel diperlukan sebelum publikasi direktori.');
        }
        MasterRegistry::set(MasterRegistry::KEY_DIRTY, '1');
    }

    private function assertVaultReady(): void
    {
        if (!Schema::hasTable('eduxid_initial_credentials')) {
            throw new RuntimeException('Tabel eduxid_initial_credentials belum tersedia. Jalankan migrasi Laravel terlebih dahulu.');
        }
    }
}

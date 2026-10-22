<?php

namespace App\Concerns;

use App\Models\Teacher;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * ============================================================
 * [FASE-0 / P4 / EX-08 / LA-09 / SR-07] RESOLVES TEACHER
 * ============================================================
 * Satu resolver guru untuk seluruh modul — menggantikan 5 varian
 * dengan fallback BERBEDA (ada yang hanya relasi user, ada yang
 * fallback NIP/NIK/NUPTK, ada yang sadar soft-delete, ada yang tidak).
 *
 * Semantik terpilih (paling lengkap dari semua varian):
 *   1. users.id → teachers.user_id
 *   2. users.login_id → teachers.nip / nik / nuptk
 *   Soft-delete teachers dihormati bila kolom ada (schema-tolerant).
 * ============================================================
 */
trait ResolvesTeacher
{
    protected static ?bool $resolvesTeacherSoftDelete = null;

    protected function teachersHaveSoftDelete(): bool
    {
        if (self::$resolvesTeacherSoftDelete === null) {
            try {
                self::$resolvesTeacherSoftDelete = Schema::hasColumn('teachers', 'deleted_at');
            } catch (Throwable) {
                self::$resolvesTeacherSoftDelete = false;
            }
        }

        return self::$resolvesTeacherSoftDelete;
    }

    protected function resolveTeacher(): ?Teacher
    {
        $user = auth()->user();

        if (!$user) {
            return null;
        }

        $byUser = Teacher::where('user_id', $user->id);

        if ($this->teachersHaveSoftDelete()) {
            $byUser->whereNull('deleted_at');
        }

        $teacher = $byUser->first();

        if ($teacher) {
            return $teacher;
        }

        $loginId = trim((string) ($user->login_id ?? ''));

        if ($loginId === '') {
            return null;
        }

        $byLogin = Teacher::where(function ($query) use ($loginId) {
            $query->where('nip', $loginId)
                ->orWhere('nik', $loginId)
                ->orWhere('nuptk', $loginId);
        });

        if ($this->teachersHaveSoftDelete()) {
            $byLogin->whereNull('deleted_at');
        }

        return $byLogin->first();
    }

    protected function resolveTeacherId(): ?int
    {
        $teacher = $this->resolveTeacher();

        return $teacher !== null ? (int) $teacher->id : null;
    }

    /** Satu semantik cek admin (method model bila ada, fallback role). */
    protected function isAdminUser(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if (method_exists($user, 'isAdmin')) {
            return (bool) $user->isAdmin();
        }

        return ($user->role ?? '') === 'admin';
    }
}
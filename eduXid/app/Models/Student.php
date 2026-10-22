<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Student extends Model
{
    /**
     * Mengizinkan mass-assignment untuk semua kolom kecuali id.
     *
     * @var array<int, string>
     */
    protected $guarded = ['id'];

    /**
     * ============================================================
     * RELASI KE AKUN USER
     * ============================================================
     *
     * Hubungan:
     *
     * users.login_id = students.nisn
     *
     * Contoh:
     *
     * users.login_id = 0104217170
     * students.nisn  = 0104217170
     */
    public function user(): HasOne
    {
        return $this->hasOne(
            User::class,
            'login_id',
            'nisn'
        );
    }

    /**
     * ============================================================
     * RELASI KE PENDAFTARAN KELAS
     * ============================================================
     *
     * Satu siswa dapat memiliki beberapa enrollment.
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(
            Enrollment::class,
            'student_id'
        );
    }
}
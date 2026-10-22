<?php

namespace App\Models;


use App\Services\AcademicPeriodService;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'login_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function student()
    {
        return $this->hasOne(Student::class, 'nisn', 'login_id');
    }

    public function roleName(): string
    {
        return strtolower(trim((string) $this->role));
    }

    public function teacher()
    {
        $loginId = trim((string) $this->login_id);

        if ($loginId === '') {
            return null;
        }

        return Teacher::query()
            ->whereNull('deleted_at')
            ->where(function ($query) use ($loginId) {
                $query->where('nip', $loginId)
                    ->orWhere('nik', $loginId)
                    ->orWhere('nuptk', $loginId);
            })
            ->first();
    }

    public function hasTeachingAssignments(): bool
    {
        if (!in_array(
            $this->roleName(),
            ['guru', 'wali_kelas', 'guru_bk', 'kepala_sekolah'],
            true
        )) {
            return false;
        }

        $teacher = $this->teacher();

        if (!$teacher) {
            return false;
        }

        $query = DB::table('teaching_assignments')
            ->join(
                'homerooms',
                'teaching_assignments.homeroom_id',
                '=',
                'homerooms.id'
            )
            ->join(
                'subjects',
                'teaching_assignments.subject_id',
                '=',
                'subjects.id'
            )
            ->where('teaching_assignments.teacher_id', $teacher->id);

        $semesterId = AcademicPeriodService::selectedOrLatest();

        if ($semesterId !== null && $semesterId !== '') {
            $query->where('homerooms.semester_id', $semesterId);
        }

        return $query->exists();
    }

    public function hasAdditionalDuty(string $duty): bool
    {
        if ($this->roleName() !== 'tendik') {
            return false;
        }

        $teacher = $this->teacher();

        if (!$teacher) {
            return false;
        }

        $duty = strtolower(trim($duty));

        $aliases = [
            'wali_kelas' => 'wali',
            'ekstrakurikuler' => 'ekskul',
            'pembina_ekskul' => 'ekskul',
            'bimbingan_konseling' => 'bk',
            'guru_bk' => 'bk',
            'koordinator_kokurikuler' => 'kokurikuler',
        ];

        $duty = $aliases[$duty] ?? $duty;
        $semesterId = $this->activeSemesterForAdditionalDuty();

        if ($duty === 'wali') {
            if (!Schema::hasTable('homerooms')) {
                return false;
            }

            $query = DB::table('homerooms')
                ->where('homeroom_teacher_id', $teacher->id)
                ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
                ->where('jenis_rombel', 'not like', '%Pilihan%');

            if (
                $semesterId !== null
                && Schema::hasColumn('homerooms', 'semester_id')
            ) {
                $query->where('semester_id', $semesterId);
            }

            return $query->exists();
        }

        if ($duty === 'ekskul') {
            if (!Schema::hasTable('homerooms')) {
                return false;
            }

            $query = DB::table('homerooms')
                ->where('homeroom_teacher_id', $teacher->id)
                ->where('jenis_rombel', 'like', '%Ekstrakurikuler%');

            if (
                $semesterId !== null
                && Schema::hasColumn('homerooms', 'semester_id')
            ) {
                $query->where('semester_id', $semesterId);
            }

            return $query->exists();
        }

        if ($duty === 'bk') {
            if (
                !Schema::hasTable('teaching_assignments')
                || !Schema::hasTable('subjects')
            ) {
                return false;
            }

            $query = DB::table('teaching_assignments')
                ->join(
                    'subjects',
                    'teaching_assignments.subject_id',
                    '=',
                    'subjects.id'
                )
                ->where(
                    'teaching_assignments.teacher_id',
                    $teacher->id
                )
                ->where(function ($query) {
                    $query
                        ->where(
                            'subjects.nama_mata_pelajaran',
                            'like',
                            '%Bimbingan%'
                        )
                        ->orWhere(
                            'subjects.nama_mata_pelajaran',
                            'like',
                            '%Konseling%'
                        );
                });

            if (
                $semesterId !== null
                && Schema::hasTable('homerooms')
            ) {
                $query
                    ->join(
                        'homerooms',
                        'teaching_assignments.homeroom_id',
                        '=',
                        'homerooms.id'
                    )
                    ->where('homerooms.semester_id', $semesterId);
            }

            return $query->exists();
        }

        if ($duty === 'kokurikuler') {
            if (!Schema::hasTable('kokurikulers')) {
                return false;
            }

            $query = DB::table('kokurikulers')
                ->where('koordinator_id', $teacher->id);

            if (
                $semesterId !== null
                && Schema::hasColumn('kokurikulers', 'semester_id')
            ) {
                $query->where('semester_id', $semesterId);
            }

            return $query->exists();
        }

        return false;
    }

    public function hasAnyAdditionalDuty(): bool
    {
        foreach (['wali', 'ekskul', 'bk', 'kokurikuler'] as $duty) {
            if ($this->hasAdditionalDuty($duty)) {
                return true;
            }
        }

        return false;
    }

    private function activeSemesterForAdditionalDuty(): ?string
    {
        $semesterId = trim((string) session('semester_aktif', ''));

        if ($semesterId !== '') {
            return $semesterId;
        }

        if (
            !Schema::hasTable('homerooms')
            || !Schema::hasColumn('homerooms', 'semester_id')
        ) {
            return null;
        }

        $semesterId = trim((string) DB::table('homerooms')
            ->where('jenis_rombel', 'not like', '%Ekstrakurikuler%')
            ->where('jenis_rombel', 'not like', '%Pilihan%')
            ->max('semester_id'));

        if ($semesterId !== '') {
            return $semesterId;
        }

        $semesterId = trim((string) DB::table('homerooms')
            ->max('semester_id'));

        return $semesterId !== '' ? $semesterId : null;
    }
    public function canTeach(): bool
    {
        if (in_array($this->roleName(), ['guru', 'wali_kelas'], true)) {
            return true;
        }

        return in_array($this->roleName(), ['kepala_sekolah', 'guru_bk'], true)
            && $this->hasTeachingAssignments();
    }

    public function isAdmin(): bool
    {
        return $this->roleName() === 'admin';
    }

    public function isGuru(): bool
    {
        if (in_array($this->roleName(), ['guru', 'wali_kelas', 'guru_bk'], true)) {
            return true;
        }

        return $this->roleName() === 'kepala_sekolah'
            && $this->hasTeachingAssignments();
    }

    public function isWaliKelas(): bool
    {
        return $this->roleName() === 'wali_kelas';
    }

    public function isGuruBK(): bool
    {
        return $this->roleName() === 'guru_bk';
    }

    public function isSiswa(): bool
    {
        return $this->roleName() === 'siswa';
    }
}

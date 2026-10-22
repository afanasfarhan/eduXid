<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Teacher extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $table = 'teachers';

    /**
     * =========================================================
     * MASS ASSIGNMENT
     * =========================================================
     */
    protected $fillable = [
        'nama',
        'gelar_depan',
        'gelar_belakang',
        'gelar_akademik_dapodik',
        'nip',
        'nik',
        'nuptk',
        'ptk_terdaftar_id',
        'dapodik_ptk_id',
        'jenis_kelamin',
        'tempat_lahir',
        'tanggal_lahir',
        'jabatan_ptk',
        'jenis_ptk',
        'status_kepegawaian',
        'pendidikan_terakhir',
        'bidang_studi_terakhir',
        'pangkat_golongan_terakhir',
        'pangkat_golongan',
        'pangkat',
        'golongan',
        'tmt_cpns',
        'tmt_pns',
        'tmt_jabatan',
        'tmt_golongan',
        'no_hp',
        'email',
        'alamat',
        'foto',
    ];

    /**
     * =========================================================
     * APPENDS
     * =========================================================
     */
    protected $appends = [
        'nama_lengkap',
        'gelar_lengkap',
        'pangkat_lengkap',
        'is_kepala_sekolah',
        'is_guru_bk',
        'is_wali_kelas',
    ];

    /**
     * =========================================================
     * CASTS
     * =========================================================
     */
    protected function casts(): array
    {
        return [
            'tanggal_lahir' => 'date',
            'tmt_cpns'      => 'date',
            'tmt_pns'       => 'date',
            'tmt_jabatan'   => 'date',
            'tmt_golongan'  => 'date',
            'deleted_at'    => 'datetime',
        ];
    }

    /**
     * =========================================================
     * KONSTANTA PANGKAT PNS
     * =========================================================
     */
    public const PANGKAT_PNS = [

        'I/a'   => 'Juru Muda, I/a',
        'I/b'   => 'Juru Muda Tk. I, I/b',
        'I/c'   => 'Juru, I/c',
        'I/d'   => 'Juru Tk. I, I/d',

        'II/a'  => 'Pengatur Muda, II/a',
        'II/b'  => 'Pengatur Muda Tk. I, II/b',
        'II/c'  => 'Pengatur, II/c',
        'II/d'  => 'Pengatur Tk. I, II/d',

        'III/a' => 'Penata Muda, III/a',
        'III/b' => 'Penata Muda Tk. I, III/b',
        'III/c' => 'Penata, III/c',
        'III/d' => 'Penata Tk. I, III/d',

        'IV/a'  => 'Pembina, IV/a',
        'IV/b'  => 'Pembina Tk. I, IV/b',
        'IV/c'  => 'Pembina Utama Muda, IV/c',
        'IV/d'  => 'Pembina Utama Madya, IV/d',
        'IV/e'  => 'Pembina Utama, IV/e',
    ];

    /**
     * =========================================================
     * RELATIONS
     * =========================================================
     */

    /*
    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class, 'school_id', 'id');
    }
    */

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(
            TeachingAssignment::class,
            'teacher_id',
            'id'
        );
    }

    public function homerooms(): HasMany
    {
        return $this->hasMany(
            Homeroom::class,
            'teacher_id',
            'id'
        );
    }

    /**
     * =========================================================
     * SCOPES
     * =========================================================
     */

    public function scopeActive($query)
    {
        return $query->where(function ($q) {
            $q->whereNull('tanggal_keluar')
              ->orWhere('tanggal_keluar', '>', now());
        });
    }

    public function scopeKepalaSekolah($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw(
                'LOWER(jabatan_ptk) LIKE ?',
                ['%kepala sekolah%']
            )->orWhereRaw(
                'LOWER(jenis_ptk) LIKE ?',
                ['%kepala sekolah%']
            );
        });
    }

    public function scopeGuruBK($query)
    {
        return $query->where(function ($q) {
            $q->whereRaw(
                'LOWER(jabatan_ptk) LIKE ?',
                ['%bk%']
            )->orWhereRaw(
                'LOWER(jabatan_ptk) LIKE ?',
                ['%bimbingan%']
            )->orWhereRaw(
                'LOWER(jabatan_ptk) LIKE ?',
                ['%konseling%']
            )->orWhereRaw(
                'LOWER(jenis_ptk) LIKE ?',
                ['%bk%']
            )->orWhereRaw(
                'LOWER(jenis_ptk) LIKE ?',
                ['%konseling%']
            );
        });
    }

    public function scopeWaliKelas($query)
    {
        return $query->whereRaw(
            'LOWER(jabatan_ptk) LIKE ?',
            ['%wali kelas%']
        );
    }

    /**
     * =========================================================
     * ACCESSOR — NAMA LENGKAP
     * =========================================================
     */
    public function getNamaLengkapAttribute(): string
    {
        $nama = trim((string) ($this->nama ?? ''));

        if ($nama === '') {
            return '';
        }

        $nama = $this->normalizeNama($nama);

        $gelarDepan = trim(
            (string) ($this->gelar_depan ?? '')
        );

        $gelarBelakang = trim(
            (string) ($this->gelar_belakang ?? '')
        );

        if ($gelarDepan !== '') {
            $nama = $gelarDepan . ' ' . $nama;
        }

        if ($gelarBelakang !== '') {
            $nama .= ', ' . $gelarBelakang;
        }

        return $nama;
    }

    /**
     * =========================================================
     * ACCESSOR — GELAR LENGKAP
     * =========================================================
     */
    public function getGelarLengkapAttribute(): string
    {
        $depan = trim(
            (string) ($this->gelar_depan ?? '')
        );

        $belakang = trim(
            (string) ($this->gelar_belakang ?? '')
        );

        if ($depan !== '' && $belakang !== '') {
            return $depan . ', ' . $belakang;
        }

        return $depan ?: $belakang;
    }

    /**
     * =========================================================
     * ACCESSOR — PANGKAT LENGKAP
     * =========================================================
     */
    public function getPangkatLengkapAttribute(): string
    {
        $nilai = trim(
            (string) (
                $this->pangkat_golongan_terakhir
                ?? $this->pangkat_golongan
                ?? $this->pangkat
                ?? $this->golongan
                ?? ''
            )
        );

        if ($nilai === '') {
            return '';
        }

        $kode = strtoupper(
            str_replace(' ', '', $nilai)
        );

        foreach (self::PANGKAT_PNS as $gol => $nama) {
            if (
                $kode === strtoupper(
                    str_replace(' ', '', $gol)
                )
            ) {
                return $nama;
            }
        }

        if (
            preg_match(
                '/\b(I{1,3}|IV|V)\/[a-e]\b/i',
                $nilai,
                $match
            )
        ) {
            $kodeDitemukan = strtoupper($match[0]);

            return self::PANGKAT_PNS[$kodeDitemukan]
                ?? $nilai;
        }

        return $nilai;
    }

    /**
     * =========================================================
     * ACCESSOR — KEPALA SEKOLAH
     * =========================================================
     */
    public function getIsKepalaSekolahAttribute(): bool
    {
        $jabatan = strtolower(
            trim((string) ($this->jabatan_ptk ?? ''))
        );

        $jenis = strtolower(
            trim((string) ($this->jenis_ptk ?? ''))
        );

        return str_contains($jabatan, 'kepala sekolah')
            || str_contains($jenis, 'kepala sekolah');
    }

    /**
     * =========================================================
     * ACCESSOR — GURU BK
     * =========================================================
     */
    public function getIsGuruBkAttribute(): bool
    {
        $jabatan = strtolower(
            trim((string) ($this->jabatan_ptk ?? ''))
        );

        $jenis = strtolower(
            trim((string) ($this->jenis_ptk ?? ''))
        );

        return str_contains($jabatan, 'bk')
            || str_contains($jabatan, 'bimbingan')
            || str_contains($jabatan, 'konseling')
            || str_contains($jenis, 'bk')
            || str_contains($jenis, 'konseling');
    }

    /**
     * =========================================================
     * ACCESSOR — WALI KELAS
     * =========================================================
     */
    public function getIsWaliKelasAttribute(): bool
    {
        return str_contains(
            strtolower(
                trim((string) ($this->jabatan_ptk ?? ''))
            ),
            'wali kelas'
        );
    }

    /**
     * =========================================================
     * ACCESSOR — NAMA TAMPILAN
     * =========================================================
     */
    public function getNamaTampilanAttribute(): string
    {
        return $this->nama_lengkap
            ?: ($this->nama ?? '-');
    }

    /**
     * =========================================================
     * PRIVATE — NORMALISASI NAMA
     * =========================================================
     */
    private function normalizeNama(string $nama): string
    {
        $nama = mb_strtolower(
            $nama,
            'UTF-8'
        );

        $nama = preg_replace_callback(
            '/\b([\p{L}])/u',
            fn ($m) => mb_strtoupper(
                $m[1],
                'UTF-8'
            ),
            $nama
        );

        if ($nama === null) {
            return '';
        }

        $nama = preg_replace_callback(
            '/\b([A-Z])\./',
            fn ($m) => $m[1] . '.',
            $nama
        );

        $nama = preg_replace_callback(
            '/\b(I{1,3}|IV|V|IX|X{1,3}|XL|L|C|D|M)\b/i',
            fn ($m) => mb_strtoupper(
                $m[1]
            ),
            $nama
        );

        $nama = preg_replace(
            '/\b(van|der|den|von|bin|binti|al)\b/i',
            strtolower('\1'),
            $nama
        );

        return $nama ?? '';
    }
}
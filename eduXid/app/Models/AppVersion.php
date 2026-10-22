<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AppVersion extends Model
{
    /**
     * Nama tabel.
     */
    protected $table = 'app_versions';

    /**
     * Kolom yang boleh diisi.
     */
    protected $fillable = [
        'version',
        'is_current',
        'name',
        'description',
        'activated_at',
    ];

    /**
     * Casting.
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * Ambil versi yang sedang aktif.
     */
    public function scopeCurrent($query)
    {
        return $query->where(
            'is_current',
            true
        );
    }

    /**
     * Apakah versi ini sedang aktif?
     */
    public function isCurrent(): bool
    {
        return (bool) $this->is_current;
    }

    /**
     * Ambil patch yang menuju versi ini.
     *
     * Hubungan dilakukan berdasarkan nomor versi,
     * karena tabel patches tidak mempunyai target_version.
     */
    public function patch()
    {
        return $this->hasOne(
            Patch::class,
            'version',
            'version'
        );
    }

    /**
     * Ambil semua patch dengan nomor versi
     * yang lebih tinggi dari versi ini.
     */
    public function patches()
    {
        return Patch::query()
            ->where(
                'version',
                '>',
                $this->version
            )
            ->orderBy('version');
    }
}
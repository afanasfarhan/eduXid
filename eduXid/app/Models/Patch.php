<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Patch extends Model
{
    /**
     * Nama tabel.
     */
    protected $table = 'patches';

    /**
     * Kolom yang boleh diisi.
     */
    protected $fillable = [
        'version',
        'requires_version',
        'name',
        'description',
        'release_notes',
        'type',
        'status',
        'installed_at',
    ];

    /**
     * Casting.
     */
    protected function casts(): array
    {
        return [
            'installed_at' => 'datetime',
        ];
    }

    /**
     * Patch yang sudah berhasil dipasang.
     */
    public function scopeInstalled($query)
    {
        return $query->where(
            'status',
            'installed'
        );
    }

    /**
     * Patch yang belum dipasang.
     */
    public function scopePending($query)
    {
        return $query->where(
            'status',
            'pending'
        );
    }

    /**
     * Apakah patch sudah terpasang?
     */
    public function isInstalled(): bool
    {
        return $this->status === 'installed';
    }

    /**
     * Apakah patch masih menunggu pemasangan?
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Apakah patch gagal?
     */
    public function isFailed(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Apakah patch cocok dengan versi aplikasi saat ini?
     */
    public function requiresVersion(string $version): bool
    {
        /*
         * Jika requires_version kosong, patch lama tetap
         * dianggap kompatibel agar tidak merusak patch
         * yang sudah ada sebelum metadata diperkenalkan.
         */
        if (!$this->requires_version) {
            return true;
        }

        return $this->requires_version === $version;
    }
}

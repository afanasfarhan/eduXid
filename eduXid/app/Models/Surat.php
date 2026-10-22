<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Surat extends Model
{
    use HasFactory;

    protected $table = 'surats';

    protected $fillable = [
        'user_id',
        'jenis_surat',
        'nomor_surat',
        'perihal',
        'tanggal_surat',
        'tujuan',
        'penerima_nama',
        'penerima_tipe',
        'penandatangan_nama',
        'penandatangan_jabatan',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data'           => 'array',
            'tanggal_surat'  => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class);
    }
}
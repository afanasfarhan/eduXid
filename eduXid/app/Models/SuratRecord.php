<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SuratRecord extends Model
{
    use HasFactory;

    protected $table = 'surat_records';

    protected $fillable = [
        'user_id',
        'nomor_surat',
        'jenis_surat',
        'perihal',
        'tanggal_surat',
        'data_surat',
    ];

    protected $casts = [
        'data_surat' => 'array',
    ];
}
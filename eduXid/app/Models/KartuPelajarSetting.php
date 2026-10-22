<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KartuPelajarSetting extends Model
{
    protected $table = 'kartu_pelajar_settings';


    protected $fillable = [

        'logo_kiri',

        'logo_kanan',

        'jabatan_penanda_tangan',

        'nama_penanda_tangan',

        'nip_penanda_tangan',

        'tempat_penanda_tangan',

        'tanggal_penanda_tangan',

    ];


    protected $casts = [

        'tanggal_penanda_tangan' => 'date',

    ];
}
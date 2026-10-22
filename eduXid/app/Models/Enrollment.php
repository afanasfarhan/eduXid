<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enrollment extends Model
{
    protected $table = 'enrollments';

    protected $fillable = [
        'dapodik_anggota_rombel_id',
        'student_id',
        'homeroom_id',
        'jenis_pendaftaran',
        'pos_x',
        'pos_y',
        'catatan_wali',
        'rapor_sakit',
        'rapor_izin',
        'rapor_alpa',
        'catatan_wali_manual',
    ];


    /*
    |--------------------------------------------------------------------------
    | STUDENT
    |--------------------------------------------------------------------------
    */
    public function student()
    {
        return $this->belongsTo(
            Student::class,
            'student_id'
        );
    }


    /*
    |--------------------------------------------------------------------------
    | HOMEROOM / ROMBEL
    |--------------------------------------------------------------------------
    */
    public function homeroom()
    {
        return $this->belongsTo(
            Homeroom::class,
            'homeroom_id'
        );
    }
}
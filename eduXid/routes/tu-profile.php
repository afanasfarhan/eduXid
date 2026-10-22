<?php

use App\Http\Controllers\SchoolProfileController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'role:tendik'])
    ->prefix('tu/profil')
    ->name('tu.profil.')
    ->group(function () {
        Route::get('/', [SchoolProfileController::class, 'index'])->name('index');
        Route::get('/export', [SchoolProfileController::class, 'export'])->name('export');
    });


// V21R87: pengambilan profil hanya untuk akun sekolah terautentikasi.
Route::post('/internal/student-profile-overlay', [\App\Http\Controllers\StudentProfileOverlayController::class, 'show'])
    ->middleware(['auth', 'verified', 'throttle:60,1', 'role:admin,tendik,kepala_sekolah,guru,wali_kelas,guru_bk,pengajar,siswa'])
    ->name('student.profile.overlay');

<?php

use App\Http\Controllers\AtpController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
    'role:guru,wali_kelas,guru_bk,pengajar',
])->prefix('guru/atp')->name('guru.atp.')->group(function () {
    Route::get('/', [AtpController::class, 'index'])->name('index');
    Route::post('/', [AtpController::class, 'store'])->name('store');
    Route::get('/download', [AtpController::class, 'download'])->name('download');
    Route::post('/upload', [AtpController::class, 'upload'])->name('upload');
    Route::delete('/delete-all', [AtpController::class, 'destroyAll'])->name('destroyAll');
    Route::put('/{id}', [AtpController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('/{id}', [AtpController::class, 'destroy'])->whereNumber('id')->name('destroy');

    Route::get('/api/options', [AtpController::class, 'options'])->name('options');
    Route::get('/api/assignment/{assignment}', [AtpController::class, 'optionsByAssignment'])
        ->whereNumber('assignment')
        ->name('assignment.options');

    Route::post('/generate-descriptions/{assignment}', [AtpController::class, 'generateDescriptions'])
        ->whereNumber('assignment')
        ->name('generateDescriptions');

    Route::post('/final-description/{assignment}/{student}', [AtpController::class, 'autoDescription'])
        ->whereNumber('assignment')
        ->whereNumber('student')
        ->name('description.auto');

    Route::delete('/final-description/{assignment}/{student}', [AtpController::class, 'deleteDescription'])
        ->whereNumber('assignment')
        ->whereNumber('student')
        ->name('description.delete');
});

// Modul Ajar ditautkan melalui daftar route ATP tanpa mengubah routes/web.php.
require __DIR__ . "/modul-ajar.php";

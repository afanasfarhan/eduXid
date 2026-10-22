<?php

use App\Http\Controllers\ModulAjarController;
use Illuminate\Support\Facades\Route;

// Pertahankan role dan capability yang telah berlaku pada menu ATP.
Route::middleware(['auth', 'verified', 'role:guru,wali_kelas,guru_bk,pengajar'])
    ->prefix('guru/modul-ajar')->name('guru.modul-ajar.')
    ->group(function () {
        Route::get('/', [ModulAjarController::class, 'index'])->name('index');
        Route::post('/generate', [ModulAjarController::class, 'generate'])->middleware('throttle:6,1')->name('generate');
        Route::post('/', [ModulAjarController::class, 'store'])->name('store');
        Route::get('/{id}/edit', [ModulAjarController::class, 'edit'])->whereNumber('id')->name('edit');
        Route::get('/{id}/print', [ModulAjarController::class, 'print'])->whereNumber('id')->name('print');
        Route::post('/{id}/duplicate', [ModulAjarController::class, 'duplicate'])->whereNumber('id')->name('duplicate');
        Route::put('/{id}', [ModulAjarController::class, 'update'])->whereNumber('id')->name('update');
        Route::delete('/{id}', [ModulAjarController::class, 'destroy'])->whereNumber('id')->name('destroy');
    });

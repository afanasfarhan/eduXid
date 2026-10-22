<?php

use App\Http\Controllers\LjkCorrectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('guru/ljk-v3')->group(function () {
    Route::get('/', [LjkCorrectionController::class, 'index'])->name('guru.ljk.v3.index');
    Route::get('/template', [LjkCorrectionController::class, 'template'])->name('guru.ljk.v3.template');
    Route::get('/package/{exam}', [LjkCorrectionController::class, 'privatePackage'])->name('guru.ljk.v3.package');
    Route::get('/student/{nisn}', [LjkCorrectionController::class, 'student'])->name('guru.ljk.v3.student');
    Route::post('/package/{exam}/sync-private', [LjkCorrectionController::class, 'syncPrivate'])->name('guru.ljk.v3.syncPrivate');
    Route::post('/package/{exam}/commit', [LjkCorrectionController::class, 'commit'])->name('guru.ljk.v3.commit');
});

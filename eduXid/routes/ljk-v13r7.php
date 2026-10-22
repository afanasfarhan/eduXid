<?php

use App\Http\Controllers\LjkCorrectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->prefix('guru/ljk')->group(function () {
    Route::get('/', [LjkCorrectionController::class, 'index'])->name('guru.ljk.index');
    Route::get('/template', [LjkCorrectionController::class, 'template'])->name('guru.ljk.template');
    Route::get('/package/{exam}', [LjkCorrectionController::class, 'privatePackage'])->name('guru.ljk.package');
    Route::get('/student/{nisn}', [LjkCorrectionController::class, 'student'])->name('guru.ljk.student');
    Route::post('/package/{exam}/sync-private', [LjkCorrectionController::class, 'syncPrivate'])->name('guru.ljk.syncPrivate');
    Route::post('/package/{exam}/commit', [LjkCorrectionController::class, 'commit'])->name('guru.ljk.commit');
});

<?php

use App\Http\Controllers\LjkScannerController;
use Illuminate\Support\Facades\Route;

Route::middleware([
    'auth',
    'verified',
    'role:pengajar',
])->prefix('ujian')->group(function () {
    Route::get('/pindai-ljk', [LjkScannerController::class, 'index'])
        ->name('guru.ljk.index');

    Route::post('/pindai-ljk/preview', [LjkScannerController::class, 'preview'])
        ->name('guru.ljk.preview');

    Route::post('/pindai-ljk/finalize', [LjkScannerController::class, 'finalize'])
        ->name('guru.ljk.finalize');

    Route::post('/pindai-ljk/batch-preview', [LjkScannerController::class, 'batchPreview'])
        ->name('guru.ljk.batchPreview');

    Route::post('/pindai-ljk/batch-finalize', [LjkScannerController::class, 'batchFinalize'])
        ->name('guru.ljk.batchFinalize');
});

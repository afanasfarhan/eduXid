<?php

use App\Http\Controllers\Guru\ItemAnalysisController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', 'semester.selected'])
    ->prefix('guru/ujian/{exam}/analisis-butir')
    ->group(function () {
        Route::get('/', [ItemAnalysisController::class, 'index'])
            ->name('guru.item-analysis.index');

        Route::get('/pdf', [ItemAnalysisController::class, 'pdf'])
            ->name('guru.item-analysis.pdf');

        Route::post('/snapshot', [ItemAnalysisController::class, 'snapshot'])
            ->name('guru.item-analysis.snapshot');
    });

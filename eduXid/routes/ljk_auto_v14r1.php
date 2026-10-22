<?php

use App\Http\Controllers\Guru\LjkAutoCorrectionController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'role:pengajar'])
    ->prefix('guru/ljk-auto')
    ->name('guru.ljk-auto.')
    ->group(function () {
        Route::get('/', [LjkAutoCorrectionController::class, 'index'])->name('index');
        // V13: sesi kunci manual TANPA membuat exams atau Bank Soal.
        Route::get('/standalone', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'index'])->name('standalone.index');
        Route::post('/standalone', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'create'])->name('standalone.create');
        Route::delete('/standalone/{session}', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'destroySession'])->name('standalone.destroy');
        // Analisis sesi LJK manual; tidak bergantung pada exam_id atau Bank Soal.
        Route::get('/standalone/{session}/analisis-butir', [\App\Http\Controllers\Guru\ManualLjkItemAnalysisController::class, 'index'])->name('standalone.analysis');
        Route::get('/standalone/{session}/analisis-butir/pdf', [\App\Http\Controllers\Guru\ManualLjkItemAnalysisController::class, 'pdf'])->name('standalone.analysis.pdf');
        Route::get('/standalone/{session}', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'show'])->name('standalone.show');
        Route::get('/standalone/{session}/results', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'results'])->name('standalone.results');
        Route::post('/standalone/{session}/results', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'scan'])->name('standalone.scan');
        Route::put('/standalone/{session}/results/{scanId}', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'review'])->name('standalone.review');
        Route::get('/standalone/{session}/results/{scanId}/image', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'image'])->name('standalone.image');
        Route::delete('/standalone/{session}/results', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'clearAll'])->name('standalone.clear-all');
        Route::delete('/standalone/{session}/results/{scanId}', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'delete'])->name('standalone.delete');
        Route::post('/standalone/{session}/publish', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'publish'])->name('standalone.publish');
        Route::post('/standalone/{session}/sync-pwa', [\App\Http\Controllers\Guru\LjkStandaloneManualController::class, 'sync'])->name('standalone.sync-pwa');

        Route::get('/package/{exam}', [LjkAutoCorrectionController::class, 'package'])->name('package');
        Route::post('/manual-key/{exam}', [LjkAutoCorrectionController::class, 'saveManualKey'])->whereNumber('exam')->name('manual-key');
        Route::delete('/manual-key/{exam}', [LjkAutoCorrectionController::class, 'deleteManualKey'])->whereNumber('exam')->name('manual-key.destroy');
        Route::get('/results', [LjkAutoCorrectionController::class, 'results'])->name('results');
        Route::post('/results', [LjkAutoCorrectionController::class, 'store'])->name('store');
        Route::put('/results/{scanId}', [LjkAutoCorrectionController::class, 'update'])->name('update');
        Route::delete('/results/all/{exam}', [LjkAutoCorrectionController::class, 'clearAll'])->whereNumber('exam')->name('clear-all');
        Route::delete('/results/{scanId}', [LjkAutoCorrectionController::class, 'destroy'])->name('destroy');
        Route::get('/results/{scanId}/image', [LjkAutoCorrectionController::class, 'image'])->name('image');
        Route::post('/publish/{exam}', [LjkAutoCorrectionController::class, 'publish'])->name('publish');
        Route::post('/sync-pwa/{exam}', [LjkAutoCorrectionController::class, 'syncPwa'])->name('sync-pwa');
        Route::post('/import/zip', [LjkAutoCorrectionController::class, 'importZip'])->name('import-zip');
        Route::post('/import/pdf', [LjkAutoCorrectionController::class, 'importPdf'])->name('import-pdf');
        Route::get('/import/{batch}/{name}', [LjkAutoCorrectionController::class, 'importFile'])
            ->where('name', '[^/]+')
            ->name('import-file');
    });

<?php

use App\Http\Controllers\Api\UpdateLanController;
use Illuminate\Support\Facades\Route;

/*
 * Tempelkan ke routes/web.php.
 * Endpoint GET ini dilindungi middleware local-sync yang sudah dipakai eduXid.
 */
Route::middleware('local-sync')->prefix('api/update')->group(function () {
    Route::get('/latest', [UpdateLanController::class, 'latest'])
        ->name('local.update.latest');

    Route::get('/releases/{version}/download', [UpdateLanController::class, 'download'])
        ->where('version', '[0-9]+\.[0-9]+\.[0-9]+(-[A-Za-z0-9._-]+)?')
        ->name('local.update.download');
});

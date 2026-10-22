<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\GtkBundleController;
use App\Http\Middleware\EnforceGtkBundleOwner;
use Illuminate\Support\Facades\Route;

$webGroup = app('router')->getMiddlewareGroups()['web'] ?? [];
if (!in_array(EnforceGtkBundleOwner::class, $webGroup, true)) {
    Route::pushMiddlewareToGroup('web', EnforceGtkBundleOwner::class);
}

Route::middleware(['auth', 'verified', 'role:admin'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/pengaturan/paket-gtk', [GtkBundleController::class, 'index'])
            ->name('admin.gtk-bundles.index');

        Route::post('/pengaturan/paket-gtk/prepare', [GtkBundleController::class, 'prepare'])
            ->name('admin.gtk-bundles.prepare');

        Route::post('/pengaturan/paket-gtk/create', [GtkBundleController::class, 'create'])
            ->name('admin.gtk-bundles.create');

        Route::get('/pengaturan/paket-gtk/{id}/download', [GtkBundleController::class, 'download'])
            ->whereUuid('id')
            ->name('admin.gtk-bundles.download');
    });

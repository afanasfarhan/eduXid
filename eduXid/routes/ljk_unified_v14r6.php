<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| LJK Unified v14R6 — retire halaman koreksi LJK lama
|--------------------------------------------------------------------------
|
| Fungsi khusus Template LJK dan Kalibrasi tetap hidup.
| Hanya halaman scanner/koreksi LJK lama yang dialihkan ke halaman
| canonical /guru/ljk-auto agar tidak ada dua halaman koreksi.
|
*/

$legacyLjkUris = [];

foreach (Route::getRoutes() as $existingRoute) {
    if (!in_array('GET', $existingRoute->methods(), true)) {
        continue;
    }

    $uri = trim((string) $existingRoute->uri(), '/');
    $name = strtolower(trim((string) ($existingRoute->getName() ?? '')));
    $action = strtolower(trim((string) $existingRoute->getActionName()));
    $haystack = strtolower($uri . ' ' . $name . ' ' . $action);

    if (!str_contains($haystack, 'ljk')) {
        continue;
    }

    if (
        str_starts_with($uri, 'guru/ljk-auto')
        || str_contains($uri, '{')
        || str_contains($haystack, 'template')
        || str_contains($haystack, 'kalibr')
        || str_contains($haystack, 'calibr')
        || str_contains($haystack, 'cetak')
        || str_contains($haystack, 'print')
        || str_contains($haystack, 'download')
        || str_contains($haystack, 'export')
        || str_contains($haystack, 'api/')
        || str_contains($haystack, '.json')
        || str_contains($haystack, 'config')
    ) {
        continue;
    }

    $looksLikeOldPage =
        preg_match('~(^|/)ljk(?:-v\d+)?$~', $uri)
        || str_contains($haystack, 'scanner')
        || str_contains($haystack, 'scan')
        || str_contains($haystack, 'koreksi');

    if ($looksLikeOldPage) {
        $legacyLjkUris[$uri] = true;
    }
}

foreach (array_keys($legacyLjkUris) as $legacyUri) {
    Route::get('/' . $legacyUri, function () {
        return redirect()->route('guru.ljk-auto.index');
    })
        ->middleware(['auth', 'role:pengajar'])
        ->name('guru.ljk.legacy.redirect.' . substr(md5($legacyUri), 0, 10));
}

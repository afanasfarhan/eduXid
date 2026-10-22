<?php

/*
|--------------------------------------------------------------------------
| P9 Local Media Route
|--------------------------------------------------------------------------
| Tambahkan import:
|
| use App\Http\Controllers\LocalMediaController;
|
| Lalu tambahkan route ini di routes/web.php DI LUAR group role tertentu,
| tetapi tetap di bawah middleware web Laravel.
|
*/

Route::get('/media/{path}', [LocalMediaController::class, 'show'])
    ->where('path', '.*')
    ->name('local-media.show');

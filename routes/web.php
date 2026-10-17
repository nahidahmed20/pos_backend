<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('pos.index')
    : redirect()->route('ui.login'));

Route::get('/login', fn () => view('auth.login'))
    ->middleware('guest')
    ->name('ui.login');

Route::get('/pos', function () {
    abort_unless(auth()->user()->hasAnyPermission([
        'manage pos',
        'manage products',
        'manage purchases',
        'manage expenses',
        'manage branches',
        'view reports',
    ]), 403);

    return view('pos.index');
})->middleware('auth')->name('pos.index');

require __DIR__.'/auth.php';

<?php

use App\Http\Controllers\FotoPenggunaController;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect(Filament::getPanel('admin')->getLoginUrl()));

Route::get('/foto-pengguna/{user}', FotoPenggunaController::class)
    ->middleware('auth')
    ->name('pengguna.foto');

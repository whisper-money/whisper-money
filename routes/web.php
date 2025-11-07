<?php

use App\Http\Controllers\EncryptionController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('setup-encryption', function () {
        return Inertia::render('auth/setup-encryption');
    })->name('setup-encryption');

    Route::post('api/encryption/setup', [EncryptionController::class, 'setup']);
    Route::get('api/encryption/message', [EncryptionController::class, 'getMessage']);
    Route::put('api/encryption/message', [EncryptionController::class, 'updateMessage']);
});

Route::middleware(['auth', 'verified', 'redirect.encryption'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');
});

require __DIR__.'/settings.php';

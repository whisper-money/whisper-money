<?php

use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Sync\AccountSyncController;
use App\Http\Controllers\Sync\BankSyncController;
use App\Http\Controllers\Sync\CategorySyncController;
use App\Http\Controllers\TransactionController;
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

    Route::get('api/sync/categories', [CategorySyncController::class, 'index']);
    Route::get('api/sync/accounts', [AccountSyncController::class, 'index']);
    Route::get('api/sync/banks', [BankSyncController::class, 'index']);
    Route::get('api/sync/automation-rules', [\App\Http\Controllers\Sync\AutomationRuleSyncController::class, 'index']);
    Route::get('api/sync/transactions', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'index']);
    Route::post('api/sync/transactions', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'store']);
    Route::patch('api/sync/transactions/{transaction}', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'update']);
    Route::delete('api/sync/transactions/{transaction}', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'destroy']);
});

Route::middleware(['auth', 'verified', 'redirect.encryption'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});

require __DIR__.'/settings.php';

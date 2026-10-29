<?php

use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\SpaceController;
use App\Http\Controllers\Api\V1\TransactionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| Mounted at `/api/v1` under the stateless `api-v1` middleware group (see
| bootstrap/app.php): bearer tokens only, no session, cookies or CSRF, kept
| apart from the internal session API in routes/api.php. Route names are
| prefixed `api.v1.` and double as the endpoint in the usage metrics.
|
*/

Route::get('spaces', [SpaceController::class, 'index'])->name('spaces.index');

Route::whereUuid('account')->group(function (): void {
    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::get('accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('accounts.balances.index');
    Route::post('accounts/{account}/balances', [AccountBalanceController::class, 'store'])->name('accounts.balances.store');
});

Route::whereUuid('transaction')->group(function (): void {
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});

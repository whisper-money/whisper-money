<?php

use App\Http\Controllers\Api\V1\AccountBalanceController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\SpaceController;
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

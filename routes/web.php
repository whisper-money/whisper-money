<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\Sync\AccountBalanceSyncController;
use App\Http\Controllers\Sync\AccountSyncController;
use App\Http\Controllers\Sync\BankSyncController;
use App\Http\Controllers\Sync\CategorySyncController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserLeadController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
        'hideAuthButtons' => config('landing.hide_auth_buttons', false),
    ]);
})->name('home');

Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('robots.txt', [RobotsController::class, 'index'])->name('robots');

Route::post('user-leads', [UserLeadController::class, 'store'])->name('user-leads.store');

Route::get('privacy', function () {
    return Inertia::render('privacy');
})->name('privacy');

Route::get('terms', function () {
    return Inertia::render('terms');
})->name('terms');

if (config('landing.hide_auth_buttons')) {
    Route::match(['GET', 'POST'], 'login', fn () => abort(404));
    Route::match(['GET', 'POST'], 'register', fn () => abort(404));
    Route::post('logout', fn () => abort(404));
    Route::get('forgot-password', fn () => abort(404));
    Route::post('forgot-password', fn () => abort(404));
    Route::get('reset-password/{token}', fn () => abort(404));
    Route::post('reset-password', fn () => abort(404));
}

Route::middleware(['auth'])->group(function () {
    Route::get('setup-encryption', function () {
        return Inertia::render('auth/setup-encryption');
    })->name('setup-encryption');

    Route::post('api/encryption/setup', [EncryptionController::class, 'setup']);
    Route::get('api/encryption/message', [EncryptionController::class, 'getMessage']);

    Route::get('api/sync/categories', [CategorySyncController::class, 'index']);
    Route::get('api/sync/accounts', [AccountSyncController::class, 'index']);
    Route::get('api/sync/banks', [BankSyncController::class, 'index']);
    Route::get('api/sync/automation-rules', [\App\Http\Controllers\Sync\AutomationRuleSyncController::class, 'index']);
    Route::get('api/sync/transactions', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'index']);
    Route::post('api/sync/transactions', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'store']);
    Route::patch('api/sync/transactions/{transaction}', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'update']);
    Route::delete('api/sync/transactions/{transaction}', [\App\Http\Controllers\Sync\TransactionSyncController::class, 'destroy']);
    Route::get('api/sync/account-balances', [AccountBalanceSyncController::class, 'index']);
    Route::post('api/sync/account-balances', [AccountBalanceSyncController::class, 'store']);
    Route::patch('api/sync/account-balances/{accountBalance}', [AccountBalanceSyncController::class, 'update']);
    Route::put('api/accounts/{account}/balance/current', [AccountBalanceController::class, 'updateCurrent'])->name('accounts.balance.update-current');

    // Dashboard Analytics
    Route::prefix('api/dashboard')->group(function () {
        Route::get('net-worth', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'netWorth']);
        Route::get('monthly-spending', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'monthlySpending']);
        Route::get('cash-flow', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'cashFlow']);
        Route::get('net-worth-evolution', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'netWorthEvolution']);
        Route::get('account-balances', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'accountBalances']);
        Route::get('top-categories', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'topCategories']);
    });
});

Route::middleware(['auth', 'verified', 'redirect.encryption'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});

require __DIR__.'/settings.php';

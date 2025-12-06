<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubscriptionController;
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
        'subscriptionsEnabled' => config('subscriptions.enabled', false),
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
    Route::match(['GET', 'POST'], 'register', fn () => abort(404));
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
    Route::get('api/accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('accounts.balances.index');
    Route::delete('api/accounts/{account}/balances/{accountBalance}', [AccountBalanceController::class, 'destroy'])->name('accounts.balances.destroy');

    // Dashboard Analytics
    Route::prefix('api/dashboard')->group(function () {
        Route::get('net-worth', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'netWorth']);
        Route::get('monthly-spending', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'monthlySpending']);
        Route::get('cash-flow', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'cashFlow']);
        Route::get('net-worth-evolution', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'netWorthEvolution']);
        Route::get('top-categories', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'topCategories']);
        Route::get('account/{account}/balance-evolution', [\App\Http\Controllers\Api\DashboardAnalyticsController::class, 'accountBalanceEvolution']);
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('subscribe', [SubscriptionController::class, 'index'])->name('subscribe');
    Route::get('subscribe/checkout', [SubscriptionController::class, 'checkout'])->name('subscribe.checkout');
    Route::get('subscribe/success', [SubscriptionController::class, 'success'])->name('subscribe.success');
    Route::get('subscribe/cancel', [SubscriptionController::class, 'cancel'])->name('subscribe.cancel');
});

Route::middleware(['auth', 'verified', 'redirect.encryption', 'subscribed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.list');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
});

require __DIR__.'/settings.php';

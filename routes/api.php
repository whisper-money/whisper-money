<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Sync\AccountBalanceSyncController;
use App\Http\Controllers\Sync\AccountSyncController;
use App\Http\Controllers\Sync\AutomationRuleSyncController;
use App\Http\Controllers\Sync\BankSyncController;
use App\Http\Controllers\Sync\CategorySyncController;
use App\Http\Controllers\Sync\TransactionSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Encryption
    Route::post('encryption/setup', [EncryptionController::class, 'setup']);
    Route::get('encryption/message', [EncryptionController::class, 'getMessage']);

    // Sync
    Route::prefix('sync')->group(function () {
        Route::get('categories', [CategorySyncController::class, 'index']);
        Route::get('accounts', [AccountSyncController::class, 'index']);
        Route::get('banks', [BankSyncController::class, 'index']);
        Route::get('automation-rules', [AutomationRuleSyncController::class, 'index']);

        Route::get('transactions', [TransactionSyncController::class, 'index']);
        Route::post('transactions', [TransactionSyncController::class, 'store']);
        Route::patch('transactions/{transaction}', [TransactionSyncController::class, 'update']);
        Route::delete('transactions/{transaction}', [TransactionSyncController::class, 'destroy']);

        Route::get('account-balances', [AccountBalanceSyncController::class, 'index']);
        Route::post('account-balances', [AccountBalanceSyncController::class, 'store']);
        Route::patch('account-balances/{accountBalance}', [AccountBalanceSyncController::class, 'update']);
    });

    // Account Balances
    Route::put('accounts/{account}/balance/current', [AccountBalanceController::class, 'updateCurrent'])->name('api.accounts.balance.update-current');
    Route::get('accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('api.accounts.balances.index');
    Route::delete('accounts/{account}/balances/{accountBalance}', [AccountBalanceController::class, 'destroy'])->name('api.accounts.balances.destroy');

    // Dashboard Analytics
    Route::prefix('dashboard')->group(function () {
        Route::get('net-worth', [DashboardAnalyticsController::class, 'netWorth']);
        Route::get('monthly-spending', [DashboardAnalyticsController::class, 'monthlySpending']);
        Route::get('cash-flow', [DashboardAnalyticsController::class, 'cashFlow']);
        Route::get('net-worth-evolution', [DashboardAnalyticsController::class, 'netWorthEvolution']);
        Route::get('top-categories', [DashboardAnalyticsController::class, 'topCategories']);
        Route::get('account/{account}/balance-evolution', [DashboardAnalyticsController::class, 'accountBalanceEvolution']);
    });
});

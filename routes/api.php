<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\Api\CashflowAnalyticsController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\ImportDataController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Sync\TransactionSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Encryption
    Route::post('encryption/setup', [EncryptionController::class, 'setup']);
    Route::get('encryption/message', [EncryptionController::class, 'getMessage']);

    // Import Data (for import drawers)
    Route::get('import/data', [ImportDataController::class, 'index']);

    // Transaction Sync (read-only, for IndexedDB client-side sync)
    Route::prefix('sync')->group(function () {
        Route::get('transactions', [TransactionSyncController::class, 'index']);
    });

    // Account Balances
    Route::put('accounts/{account}/balance/current', [AccountBalanceController::class, 'updateCurrent'])->name('api.accounts.balance.update-current');
    Route::get('accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('api.accounts.balances.index');
    Route::post('accounts/{account}/balances', [AccountBalanceController::class, 'store'])->name('api.accounts.balances.store');
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

    // Cashflow Analytics
    Route::prefix('cashflow')->group(function () {
        Route::get('summary', [CashflowAnalyticsController::class, 'summary']);
        Route::get('sankey', [CashflowAnalyticsController::class, 'sankey']);
        Route::get('trend', [CashflowAnalyticsController::class, 'trend']);
        Route::get('breakdown', [CashflowAnalyticsController::class, 'breakdown']);
    });
});

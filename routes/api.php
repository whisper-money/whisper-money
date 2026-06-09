<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CashflowAnalyticsController;
use App\Http\Controllers\Api\CategoryMonthlyBreakdownController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\ImportDataController;
use App\Http\Controllers\Api\SavedFilterController;
use App\Http\Controllers\Api\TransactionAnalysisController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Sync\TransactionSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth'])->group(function () {
    // Encryption (legacy decrypt-migration support only)
    Route::get('encryption/message', [EncryptionController::class, 'getMessage']);

    // Import Data (for import drawers)
    Route::get('import/data', [ImportDataController::class, 'index']);

    // Transaction Sync (read-only, for IndexedDB client-side sync)
    Route::prefix('sync')->group(function () {
        Route::get('transactions', [TransactionSyncController::class, 'index']);
    });

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index'])->name('api.transactions.index');
    Route::get('transactions/analysis', [TransactionAnalysisController::class, 'summary'])->name('api.transactions.analysis');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('api.transactions.bulk-update');

    // Category analysis
    Route::get('categories/{category}/monthly-breakdown', CategoryMonthlyBreakdownController::class)->name('api.categories.monthly-breakdown');

    // Accounts
    Route::get('accounts', [AccountController::class, 'index'])->name('api.accounts.index');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('api.accounts.update');

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
        Route::get('net-worth-daily-evolution', [DashboardAnalyticsController::class, 'netWorthDailyEvolution']);
        Route::get('top-categories', [DashboardAnalyticsController::class, 'topCategories']);
        Route::get('account/{account}/balance-evolution', [DashboardAnalyticsController::class, 'accountBalanceEvolution']);
        Route::get('account/{account}/daily-balance-evolution', [DashboardAnalyticsController::class, 'accountDailyBalanceEvolution']);
    });

    // Cashflow Analytics
    Route::prefix('cashflow')->group(function () {
        Route::get('summary', [CashflowAnalyticsController::class, 'summary']);
        Route::get('sankey', [CashflowAnalyticsController::class, 'sankey']);
        Route::get('trend', [CashflowAnalyticsController::class, 'trend']);
        Route::get('breakdown', [CashflowAnalyticsController::class, 'breakdown']);
    });

    // Saved transaction filters
    Route::get('saved-filters', [SavedFilterController::class, 'index'])->name('api.saved-filters.index');
    Route::post('saved-filters', [SavedFilterController::class, 'store'])->name('api.saved-filters.store');
    Route::patch('saved-filters/{savedFilter}', [SavedFilterController::class, 'update'])->name('api.saved-filters.update');
    Route::patch('saved-filters/{savedFilter}/analysis-days', [SavedFilterController::class, 'updateAnalysisDays'])->name('api.saved-filters.analysis-days');
    Route::patch('saved-filters/{savedFilter}/analysis-mode', [SavedFilterController::class, 'updateAnalysisMode'])->name('api.saved-filters.analysis-mode');
    Route::delete('saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy'])->name('api.saved-filters.destroy');
});

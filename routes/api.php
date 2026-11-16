<?php

use App\Http\Controllers\AccountBalanceController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\AccountImportConfigController;
use App\Http\Controllers\Api\CashflowAnalyticsController;
use App\Http\Controllers\Api\CategoryMonthlyBreakdownController;
use App\Http\Controllers\Api\ConvertedAmountsController;
use App\Http\Controllers\Api\DashboardAnalyticsController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\FullImportController;
use App\Http\Controllers\Api\ImportColumnSuggestionController;
use App\Http\Controllers\Api\ImportDataController;
use App\Http\Controllers\Api\SavedFilterController;
use App\Http\Controllers\Api\TransactionAnalysisController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\EncryptionController;
use App\Http\Controllers\Sync\TransactionSyncController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'throttle:300,1'])->group(function () {
    // Encryption (legacy decrypt-migration support only)
    Route::get('encryption/message', [EncryptionController::class, 'getMessage']);

    // Import Data (for import drawers)
    Route::get('import/data', [ImportDataController::class, 'index']);

    // AI column-mapping suggestions for a file being imported (headers and shapes only)
    Route::post('import/column-suggestions', ImportColumnSuggestionController::class)
        ->middleware('throttle:import-column-suggestions')
        ->name('api.import.column-suggestions');

    // Transaction Sync (read-only, for IndexedDB client-side sync)
    Route::prefix('sync')->group(function () {
        Route::get('transactions', [TransactionSyncController::class, 'index']);
    });

    // Transactions
    Route::get('transactions', [TransactionController::class, 'index'])->name('api.transactions.index');
    Route::post('transactions/check-duplicates', [TransactionController::class, 'checkDuplicates'])->name('api.transactions.check-duplicates');
    Route::get('transactions/analysis', [TransactionAnalysisController::class, 'summary'])->name('api.transactions.analysis');
    Route::get('transactions/count', [TransactionController::class, 'count'])->name('api.transactions.count');
    Route::get('transactions/listed-count', [TransactionController::class, 'listedCount'])->name('api.transactions.listed-count');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('api.transactions.bulk-update');
    // One transaction as the transactions table holds it, for an edit dialog opened by id (a widget's row)
    Route::get('transactions/{transaction}', [TransactionController::class, 'show'])
        ->whereUuid('transaction')
        ->name('api.transactions.show');

    // One amount converted between two currencies, for the transaction modal
    Route::get('exchange-rate', ExchangeRateController::class)->name('api.exchange-rate');

    // Many amounts converted into the user's currency, for the day totals of the transactions table
    Route::post('converted-amounts', ConvertedAmountsController::class)
        ->middleware('throttle:converted-amounts')
        ->name('api.converted-amounts');

    // Category analysis
    Route::get('categories/{category}/monthly-breakdown', CategoryMonthlyBreakdownController::class)->name('api.categories.monthly-breakdown');

    // Accounts
    Route::get('accounts', [AccountController::class, 'index'])->name('api.accounts.index');
    Route::put('accounts/{account}', [AccountController::class, 'update'])->name('api.accounts.update');

    // Account import configuration (per-account column mapping, synced across devices)
    Route::get('accounts/{account}/import-config', [AccountImportConfigController::class, 'show'])->name('api.accounts.import-config.show');
    Route::put('accounts/{account}/import-config', [AccountImportConfigController::class, 'update'])->name('api.accounts.import-config.update');

    // Account Balances
    Route::put('accounts/{account}/balance/current', [AccountBalanceController::class, 'updateCurrent'])->name('api.accounts.balance.update-current');
    Route::get('accounts/{account}/balances', [AccountBalanceController::class, 'index'])->name('api.accounts.balances.index');
    Route::post('accounts/{account}/balances', [AccountBalanceController::class, 'store'])->name('api.accounts.balances.store');
    Route::delete('accounts/{account}/balances/{accountBalance}', [AccountBalanceController::class, 'destroy'])->name('api.accounts.balances.destroy');

    // Dashboard Analytics
    Route::prefix('dashboard')->group(function () {
        Route::get('net-worth', [DashboardAnalyticsController::class, 'netWorth']);
        Route::get('monthly-spending', [DashboardAnalyticsController::class, 'monthlySpending']);
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
        Route::get('monthly-savings', [CashflowAnalyticsController::class, 'monthlySavings']);
    });

    // Full import from another app (the wizard). `block-demo` on the writes:
    // the demo account never imports. Outside `onboarded` and `subscribed` on
    // purpose, so the whole import works mid-onboarding.
    Route::get('full-imports/context', [FullImportController::class, 'context'])->name('api.full-imports.context');
    Route::get('full-imports/{import}', [FullImportController::class, 'show'])->name('api.full-imports.show');
    Route::middleware('block-demo')->group(function () {
        Route::post('full-imports/bank-matches', [FullImportController::class, 'bankMatches'])->name('api.full-imports.bank-matches');
        Route::post('full-imports', [FullImportController::class, 'store'])->name('api.full-imports.store');
        Route::post('full-imports/{import}/chunks', [FullImportController::class, 'storeChunk'])->name('api.full-imports.chunks.store');
        Route::post('full-imports/{import}/start', [FullImportController::class, 'start'])->name('api.full-imports.start');
    });

    // Saved transaction filters
    Route::get('saved-filters', [SavedFilterController::class, 'index'])->name('api.saved-filters.index');
    Route::post('saved-filters', [SavedFilterController::class, 'store'])->name('api.saved-filters.store');
    Route::patch('saved-filters/{savedFilter}', [SavedFilterController::class, 'update'])->name('api.saved-filters.update');
    Route::patch('saved-filters/{savedFilter}/analysis-days', [SavedFilterController::class, 'updateAnalysisDays'])->name('api.saved-filters.analysis-days');
    Route::patch('saved-filters/{savedFilter}/analysis-mode', [SavedFilterController::class, 'updateAnalysisMode'])->name('api.saved-filters.analysis-mode');
    Route::delete('saved-filters/{savedFilter}', [SavedFilterController::class, 'destroy'])->name('api.saved-filters.destroy');
});

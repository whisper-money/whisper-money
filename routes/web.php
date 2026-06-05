<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashflowController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LoanDetailController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OpenBanking\AccountMappingController;
use App\Http\Controllers\OpenBanking\AuthorizationController;
use App\Http\Controllers\OpenBanking\BinanceController;
use App\Http\Controllers\OpenBanking\BitpandaController;
use App\Http\Controllers\OpenBanking\CoinbaseController;
use App\Http\Controllers\OpenBanking\IndexaCapitalController;
use App\Http\Controllers\OpenBanking\InstitutionController;
use App\Http\Controllers\RealEstateDetailController;
use App\Http\Controllers\ReEvaluateTransactionRulesController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\UserLeadController;
use App\Models\Bank;
use App\Services\LandingAuthOverrideService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function (Request $request, LandingAuthOverrideService $landingAuthOverrideService) {
    $user = $request->user();

    if ($leadId = $request->query('lead')) {
        $request->session()->put('invited_lead_id', (string) $leadId);
    }

    $popularBanks = Cache::remember('popular-banks', now()->addDay(), function () {
        return Bank::query()
            ->whereNull('user_id')
            ->whereNotNull('logo')
            ->where('logo', '!=', '')
            ->withCount('accounts')
            ->withExists([
                'accounts as has_spanish_accounts' => fn ($query) => $query->whereHas(
                    'bankingConnection',
                    fn ($bankingConnectionQuery) => $bankingConnectionQuery->where('aspsp_country', 'ES')
                ),
            ])
            ->orderByDesc('accounts_count')
            ->orderByDesc('has_spanish_accounts')
            ->orderBy('name')
            ->limit(300)
            ->get(['name', 'logo'])
            ->map(fn (Bank $bank): array => [
                'name' => $bank->name,
                'logo' => $bank->logo,
            ])
            ->values()
            ->toArray();
    });

    $hideAuthButtons = $landingAuthOverrideService->authButtonsHidden($request);

    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()) && ! $hideAuthButtons,
        'hideAuthButtons' => $hideAuthButtons,
        'popularBanks' => $popularBanks,
    ]);
})->name('home');

Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('robots.txt', [RobotsController::class, 'index'])->name('robots');

Route::post('user-leads', [UserLeadController::class, 'store'])->name('user-leads.store');
Route::get('waitlist/check-email/{lead}', [UserLeadController::class, 'checkEmail'])->name('waitlist.check-email');
Route::get('user-leads/{lead}/verify', [UserLeadController::class, 'verify'])
    ->middleware('signed')
    ->name('user-leads.verify');
Route::get('waitlist/thank-you/{lead}', [UserLeadController::class, 'thankYou'])->name('waitlist.thank-you');

Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify.public');

Route::get('privacy', function () {
    return Inertia::render('privacy');
})->name('privacy');

Route::get('terms', function () {
    return Inertia::render('terms');
})->name('terms');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('subscribe', [SubscriptionController::class, 'index'])->name('subscribe');
    Route::get('subscribe/checkout', [SubscriptionController::class, 'checkout'])->name('subscribe.checkout');
    Route::get('subscribe/success', [SubscriptionController::class, 'success'])->name('subscribe.success');
    Route::get('subscribe/cancel', [SubscriptionController::class, 'cancel'])->name('subscribe.cancel');

    Route::middleware(['onboarded'])->group(function () {
        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');
        Route::get('onboarding/sync-status', [OnboardingController::class, 'syncStatus'])->name('onboarding.sync-status');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    });

    // Accessible during onboarding for transaction import and categorization
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
});

Route::middleware(['auth', 'verified', 'onboarded', 'subscribed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('cashflow', CashflowController::class)->name('cashflow');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.list');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}/real-estate-detail', [RealEstateDetailController::class, 'update'])->name('accounts.real-estate-detail.update');
    Route::patch('accounts/{account}/loan-detail', [LoanDetailController::class, 'update'])->name('accounts.loan-detail.update');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/categorize', [TransactionController::class, 'categorize'])->name('transactions.categorize');
    Route::post('transactions/re-evaluate-rules', [ReEvaluateTransactionRulesController::class, 'bulk'])->name('transactions.re-evaluate-rules.bulk');
    Route::get('transactions/re-evaluate-rules/status/{jobId}', [ReEvaluateTransactionRulesController::class, 'status'])->name('transactions.re-evaluate-rules.status');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    Route::post('transactions/{transaction}/re-evaluate-rules', [ReEvaluateTransactionRulesController::class, 'single'])->name('transactions.re-evaluate-rules.single');
});

// Open-banking routes are accessible without the onboarded/subscribed middleware
// so that users can connect their bank during the onboarding flow.
Route::middleware(['auth', 'verified'])->prefix('open-banking')->group(function () {
    Route::get('institutions', [InstitutionController::class, 'index'])->name('open-banking.institutions');
    Route::post('authorize', [AuthorizationController::class, 'store'])->name('open-banking.authorize');
    Route::post('connections/{connection}/reauthorize', [AuthorizationController::class, 'reauthorize'])->name('open-banking.reauthorize');
    Route::get('connections/{connection}/reconnect', [AuthorizationController::class, 'reconnect'])->name('open-banking.reconnect');
    Route::get('callback', [AuthorizationController::class, 'callback'])->name('open-banking.callback');
    Route::get('connections/{connection}/map-accounts', [AccountMappingController::class, 'show'])->name('open-banking.map-accounts');
    Route::post('connections/{connection}/map-accounts', [AccountMappingController::class, 'store'])->name('open-banking.map-accounts.store');
    Route::post('indexa-capital/connect', [IndexaCapitalController::class, 'store'])->name('open-banking.indexa-capital.connect');
    Route::post('binance/connect', [BinanceController::class, 'store'])->name('open-banking.binance.connect');
    Route::post('bitpanda/connect', [BitpandaController::class, 'store'])->name('open-banking.bitpanda.connect');
    Route::post('coinbase/connect', [CoinbaseController::class, 'store'])
        ->name('open-banking.coinbase.connect');
});

Route::middleware(['auth', 'verified', 'onboarded', 'subscribed'])->group(function () {
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
    Route::patch('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
    Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');
});

require __DIR__.'/settings.php';

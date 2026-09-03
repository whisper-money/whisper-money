<?php

use App\Features\SplitTransactions;
use App\Http\Controllers\AccountController;
use App\Http\Controllers\AgentDocsController;
use App\Http\Controllers\Ai\AiConsentController;
use App\Http\Controllers\Ai\CategorizationController;
use App\Http\Controllers\Ai\RuleSuggestionController;
use App\Http\Controllers\Auth\VerifyEmailController;
use App\Http\Controllers\BudgetController;
use App\Http\Controllers\CashflowController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentationController;
use App\Http\Controllers\IntegrationRequestController;
use App\Http\Controllers\IntegrationsController;
use App\Http\Controllers\LoanDetailController;
use App\Http\Controllers\MonthlySummaryController;
use App\Http\Controllers\MonthlySummaryShareController;
use App\Http\Controllers\MonthlySummaryUnsubscribeController;
use App\Http\Controllers\OnboardingController;
use App\Http\Controllers\OpenBanking\AccountMappingController;
use App\Http\Controllers\OpenBanking\AuthorizationController;
use App\Http\Controllers\OpenBanking\BinanceController;
use App\Http\Controllers\OpenBanking\BitpandaController;
use App\Http\Controllers\OpenBanking\CoinbaseController;
use App\Http\Controllers\OpenBanking\ConnectionAccountController;
use App\Http\Controllers\OpenBanking\IndexaCapitalController;
use App\Http\Controllers\OpenBanking\InstitutionController;
use App\Http\Controllers\OpenBanking\InteractiveBrokersController;
use App\Http\Controllers\OpenBanking\WiseController;
use App\Http\Controllers\RealEstateDetailController;
use App\Http\Controllers\ReEvaluateTransactionRulesController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SavingsGoalController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionSplitController;
use App\Models\Bank;
use App\Support\Marketing\ComparisonPages;
use App\Support\Marketing\IntegrationsPage;
use App\Support\Marketing\MarketingContent;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Pennant\Middleware\EnsureFeaturesAreActive;

Route::get('/', function () {
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

    return Inertia::render('welcome', [
        'canRegister' => config('auth.registration_enabled'),
        'popularBanks' => $popularBanks,
        'comparisonLinks' => ComparisonPages::index(app()->getLocale()),
        'integrationsLink' => IntegrationsPage::link(app()->getLocale()),
    ]);
})->name('home');

Route::get('sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('robots.txt', [RobotsController::class, 'index'])->name('robots');

/**
 * Domain ownership check for the ChatGPT app directory. It must return the bare
 * token and nothing else, so an unconfigured deploy 404s rather than serving an
 * empty body the verifier would read as a mismatched token.
 */
Route::get('.well-known/openai-apps-challenge', function (): Response {
    $token = (string) config('services.openai.apps_challenge');

    abort_if($token === '', 404);

    return response($token)->header('Content-Type', 'text/plain');
})->name('openai.apps-challenge');

Route::get('verify-email/{id}/{hash}', VerifyEmailController::class)
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify.public');

Route::get('privacy', function () {
    return Inertia::render('privacy');
})->name('privacy');

Route::get('terms', function () {
    return Inertia::render('terms');
})->name('terms');

/**
 * The documentation, and a Markdown twin of every page for agents. The `.md`
 * routes come first so a page slug never swallows the suffix, and slugs carry a
 * slash because a page nested under another page is addressed through it:
 * `documentation/transactions/import`.
 */
Route::get('documentation.md', [DocumentationController::class, 'markdown'])->name('documentation.index.markdown');
Route::get('documentation/{slug}.md', [DocumentationController::class, 'markdown'])
    ->where('slug', '[A-Za-z0-9_/-]+')
    ->name('documentation.markdown');
Route::get('documentation', [DocumentationController::class, 'show'])->name('documentation.index');
Route::get('documentation/{slug}', [DocumentationController::class, 'show'])
    ->where('slug', '[A-Za-z0-9_/-]+')
    ->name('documentation.show');

Route::get('roadmap', [RoadmapController::class, 'index'])->name('roadmap');

Route::get('llms.txt', [AgentDocsController::class, 'llms'])->name('llms');

/**
 * One URL per language, so a crawler can reach and index each one at a stable
 * address, and a Markdown twin of each for agents.
 */
foreach (MarketingContent::LOCALES as $marketingLocale) {
    $comparisonBase = MarketingContent::BASE_PATHS[$marketingLocale];
    $comparisonSlugs = ComparisonPages::slugs($marketingLocale);

    Route::get($comparisonBase.'/{slug}', [ComparisonController::class, 'show'])
        ->defaults('locale', $marketingLocale)
        ->whereIn('slug', $comparisonSlugs)
        ->name("comparison.{$marketingLocale}");

    Route::get($comparisonBase.'/{slug}.md', [ComparisonController::class, 'markdown'])
        ->defaults('locale', $marketingLocale)
        ->whereIn('slug', $comparisonSlugs)
        ->name("comparison.{$marketingLocale}.markdown");

    Route::get(IntegrationsPage::path($marketingLocale), [IntegrationsController::class, 'show'])
        ->defaults('locale', $marketingLocale)
        ->name("integrations.{$marketingLocale}");

    Route::get(IntegrationsPage::path($marketingLocale).'.md', [IntegrationsController::class, 'markdown'])
        ->defaults('locale', $marketingLocale)
        ->name("integrations.{$marketingLocale}.markdown");

    Route::get($marketingLocale === 'en' ? 'index.md' : "index.{$marketingLocale}.md", [AgentDocsController::class, 'landing'])
        ->defaults('locale', $marketingLocale)
        ->name("landing.markdown.{$marketingLocale}");
}

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('subscribe', [SubscriptionController::class, 'index'])->name('subscribe');
    Route::get('subscribe/checkout', [SubscriptionController::class, 'checkout'])->name('subscribe.checkout');
    Route::get('subscribe/success', [SubscriptionController::class, 'success'])->name('subscribe.success');
    Route::get('subscribe/cancel', [SubscriptionController::class, 'cancel'])->name('subscribe.cancel');
    Route::post('subscribe/free-plan', [SubscriptionController::class, 'chooseFreePlan'])->name('subscribe.free-plan');

    Route::middleware(['onboarded'])->group(function () {
        Route::get('onboarding', [OnboardingController::class, 'index'])->name('onboarding');
        Route::get('onboarding/sync-status', [OnboardingController::class, 'syncStatus'])->name('onboarding.sync-status');
        Route::post('onboarding/complete', [OnboardingController::class, 'complete'])->name('onboarding.complete');
    });

    // Accessible during onboarding for transaction import and categorization
    Route::post('transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::patch('transactions/bulk', [TransactionController::class, 'bulkUpdate'])->name('transactions.bulk-update');
    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');

    // AI rule suggestions — accessible during onboarding (auto-apply) and after.
    // `block-shared` only bites on the mutating routes: consent means nothing
    // when everybody holds the credentials, and every generation is a paid model
    // call.
    Route::middleware('block-shared')->group(function () {
        Route::post('ai/consent', [AiConsentController::class, 'store'])->name('ai.consent.store');
        Route::post('ai/consent/dismiss', [AiConsentController::class, 'dismiss'])->name('ai.consent.dismiss');
        Route::delete('ai/consent', [AiConsentController::class, 'destroy'])->name('ai.consent.destroy');
        Route::get('ai/categorization/{jobId}/status', [CategorizationController::class, 'status'])->name('ai.categorization.status');
        Route::prefix('ai/rule-suggestions')->name('ai.rule-suggestions.')->group(function () {
            Route::get('/', [RuleSuggestionController::class, 'show'])->name('show');
            Route::post('generate', [RuleSuggestionController::class, 'generate'])->name('generate');
            Route::post('preview', [RuleSuggestionController::class, 'preview'])->name('preview');
            Route::post('accept', [RuleSuggestionController::class, 'accept'])->name('accept');
        });
    });

    // Integration requests — community board to propose and vote on bank integrations.
    Route::get('integration-requests/data', [IntegrationRequestController::class, 'data'])->name('integration-requests.data');
    Route::post('integration-requests', [IntegrationRequestController::class, 'store'])->name('integration-requests.store');
    Route::post('integration-requests/{integrationRequest}/vote', [IntegrationRequestController::class, 'vote'])->name('integration-requests.vote');
    Route::delete('integration-requests/{integrationRequest}/vote', [IntegrationRequestController::class, 'removeVote'])->name('integration-requests.vote.destroy');
});

Route::middleware(['auth', 'verified', 'onboarded', 'subscribed'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    // The monthly summaries a reader can look back at, and the cards they can
    // share. Behind the same feature flag as the email that produces them.
    Route::get('summaries', [MonthlySummaryController::class, 'index'])->name('monthly-summaries.index');
    Route::get('summaries/{summary}', [MonthlySummaryController::class, 'show'])->name('monthly-summaries.show');
    Route::get('summaries/{summary}/card/{card}/{format}/{theme}', [MonthlySummaryController::class, 'card'])->name('monthly-summaries.card');
    Route::post('summaries/{summary}/share', [MonthlySummaryController::class, 'share'])->name('monthly-summaries.share');
    Route::delete('summaries/{summary}/share', [MonthlySummaryController::class, 'revoke'])->name('monthly-summaries.share.destroy');
    Route::post('summaries/{summary}/dismiss', [MonthlySummaryController::class, 'dismiss'])->name('monthly-summaries.dismiss');
    // Renders the dashboard with the integration-requests drawer opened on top.
    Route::get('integration-requests', [IntegrationRequestController::class, 'index'])->name('integration-requests.index');
    Route::get('cashflow', CashflowController::class)->name('cashflow');

    Route::get('accounts', [AccountController::class, 'index'])->name('accounts.list');
    Route::patch('accounts/reorder', [AccountController::class, 'reorder'])->name('accounts.reorder');
    Route::patch('accounts/{account}/visibility', [AccountController::class, 'updateVisibility'])->name('accounts.visibility');
    Route::patch('accounts/{account}/archived', [AccountController::class, 'updateArchived'])->name('accounts.archived');
    Route::get('accounts/{account}', [AccountController::class, 'show'])->name('accounts.show');
    Route::patch('accounts/{account}/real-estate-detail', [RealEstateDetailController::class, 'update'])->name('accounts.real-estate-detail.update');
    Route::patch('accounts/{account}/loan-detail', [LoanDetailController::class, 'update'])->name('accounts.loan-detail.update');

    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/categorize', [TransactionController::class, 'categorize'])->name('transactions.categorize');
    Route::post('transactions/re-evaluate-rules', [ReEvaluateTransactionRulesController::class, 'bulk'])->name('transactions.re-evaluate-rules.bulk');
    Route::get('transactions/re-evaluate-rules/status/{jobId}', [ReEvaluateTransactionRulesController::class, 'status'])->name('transactions.re-evaluate-rules.status');
    Route::delete('transactions/{transaction}', [TransactionController::class, 'destroy'])->name('transactions.destroy');
    Route::post('transactions/{transaction}/re-evaluate-rules', [ReEvaluateTransactionRulesController::class, 'single'])->name('transactions.re-evaluate-rules.single');
    // Only creating a split is gated: merging one back stays open, so turning
    // the flag off never leaves anyone holding parts they cannot undo.
    Route::post('transactions/{transaction}/split', [TransactionSplitController::class, 'store'])
        ->middleware(EnsureFeaturesAreActive::using(SplitTransactions::class))
        ->name('transactions.split.store');
    Route::delete('transactions/{transaction}/split', [TransactionSplitController::class, 'destroy'])->name('transactions.split.destroy');
});

// The bank authorization callback is intentionally unauthenticated: iOS PWAs hand the
// redirect back to Safari where the app session does not exist. The connection is
// resolved from the signed state token EnableBanking echoes back instead.
Route::get('open-banking/callback', [AuthorizationController::class, 'callback'])
    ->middleware('throttle:30,1')
    ->name('open-banking.callback');

// Open-banking routes are accessible without the onboarded/subscribed middleware
// so that users can connect their bank during the onboarding flow.
//
// `block-shared` keeps a real bank off an account whose credentials are public:
// whoever authorised it would leave their own movements on display to everybody
// else holding them, and it burns paid EnableBanking quota.
Route::middleware(['auth', 'verified', 'block-shared'])->prefix('open-banking')->group(function () {
    Route::get('institutions', [InstitutionController::class, 'index'])->name('open-banking.institutions');
    Route::post('authorize', [AuthorizationController::class, 'store'])->name('open-banking.authorize');
    Route::post('connections/{connection}/reauthorize', [AuthorizationController::class, 'reauthorize'])->name('open-banking.reauthorize');
    Route::get('connections/{connection}/reconnect', [AuthorizationController::class, 'reconnect'])->name('open-banking.reconnect');
    Route::get('connections/{connection}/map-accounts', [AccountMappingController::class, 'show'])->name('open-banking.map-accounts');
    Route::post('connections/{connection}/map-accounts', [AccountMappingController::class, 'store'])->name('open-banking.map-accounts.store');
    Route::get('connections/{connection}/accounts', [ConnectionAccountController::class, 'index'])->name('open-banking.connection-accounts.index');
    Route::post('connections/{connection}/accounts/map', [ConnectionAccountController::class, 'map'])->name('open-banking.connection-accounts.map');
    Route::post('connections/{connection}/accounts/{account}/unlink', [ConnectionAccountController::class, 'unlink'])->name('open-banking.connection-accounts.unlink');
    Route::post('indexa-capital/connect', [IndexaCapitalController::class, 'store'])->name('open-banking.indexa-capital.connect');
    Route::post('binance/connect', [BinanceController::class, 'store'])->name('open-banking.binance.connect');
    Route::post('bitpanda/connect', [BitpandaController::class, 'store'])->name('open-banking.bitpanda.connect');
    Route::post('coinbase/connect', [CoinbaseController::class, 'store'])
        ->name('open-banking.coinbase.connect');
    Route::post('wise/connect', [WiseController::class, 'store'])
        ->name('open-banking.wise.connect');
    Route::post('interactive-brokers/connect', [InteractiveBrokersController::class, 'store'])
        ->name('open-banking.interactive-brokers.connect');
});

Route::middleware(['auth', 'verified', 'onboarded', 'subscribed'])->group(function () {
    Route::get('budgets', [BudgetController::class, 'index'])->name('budgets.index');
    Route::post('budgets', [BudgetController::class, 'store'])->name('budgets.store');
    Route::get('budgets/{budget}', [BudgetController::class, 'show'])->name('budgets.show');
    Route::patch('budgets/{budget}', [BudgetController::class, 'update'])->name('budgets.update');
    Route::delete('budgets/{budget}', [BudgetController::class, 'destroy'])->name('budgets.destroy');
    Route::post('budgets/{budget}/archive', [BudgetController::class, 'archive'])->name('budgets.archive');
    Route::patch('planning/reorder', [BudgetController::class, 'reorder'])->name('planning.reorder');

    Route::post('savings-goals', [SavingsGoalController::class, 'store'])->name('savings-goals.store');
    Route::get('savings-goals/{savingsGoal}', [SavingsGoalController::class, 'show'])->name('savings-goals.show');
    Route::patch('savings-goals/{savingsGoal}', [SavingsGoalController::class, 'update'])->name('savings-goals.update');
    Route::put('savings-goals/{savingsGoal}/transactions', [SavingsGoalController::class, 'syncTransactions'])->name('savings-goals.transactions.sync');
    Route::delete('savings-goals/{savingsGoal}', [SavingsGoalController::class, 'destroy'])->name('savings-goals.destroy');
    Route::post('savings-goals/{savingsGoal}/archive', [SavingsGoalController::class, 'archive'])->name('savings-goals.archive');
});

require __DIR__.'/settings.php';

// A shared card's public page. It exists only once the owner has asked for a
// link, carries the picture and a route back to the product, and nothing else.
Route::get('s/{token}', MonthlySummaryShareController::class)
    ->where('token', '[A-Za-z0-9]{20,64}')
    ->name('monthly-summaries.shared');

// One-click unsubscribe from the monthly summary. GET for the footer link, POST
// for RFC 8058 clients that unsubscribe from the mailbox without a browser.
Route::match(['get', 'post'], 'unsubscribe/monthly-summary/{user}', MonthlySummaryUnsubscribeController::class)
    ->middleware('signed')
    ->name('monthly-summaries.unsubscribe');

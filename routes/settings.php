<?php

use App\Http\Controllers\OpenBanking\ConnectionController;
use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\ApiTokenController;
use App\Http\Controllers\Settings\AutomationRuleApplicationController;
use App\Http\Controllers\Settings\AutomationRuleController;
use App\Http\Controllers\Settings\BankController;
use App\Http\Controllers\Settings\CategoryController;
use App\Http\Controllers\Settings\ChartColorSchemeController;
use App\Http\Controllers\Settings\FullImportController;
use App\Http\Controllers\Settings\LabelController;
use App\Http\Controllers\Settings\McpTokenController;
use App\Http\Controllers\Settings\NetWorthChartLoanPreferenceController;
use App\Http\Controllers\Settings\NetWorthChartRealEstatePreferenceController;
use App\Http\Controllers\Settings\NotificationPreferenceController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TimezoneController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use App\Http\Controllers\SubscriptionCancellationController;
use App\Http\Controllers\SubscriptionController;
use App\Http\Controllers\SubscriptionPauseController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/accounts');

    Route::get('settings/account', [ProfileController::class, 'account'])->name('account.edit');
    // Blocked on shared accounts: this is where the e-mail address (and so the
    // login) and the seeded locale would be changed.
    Route::patch('settings/profile', [ProfileController::class, 'update'])
        ->middleware('block-shared')
        ->name('profile.update');
    Route::patch('settings/timezone', [TimezoneController::class, 'update'])->name('timezone.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])
        ->middleware('block-shared')
        ->name('profile.destroy');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware(['throttle:6,1', 'block-shared'])
        ->name('user-password.update');

    Route::get('settings/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('settings/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('settings/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('settings/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('settings/notifications', [NotificationPreferenceController::class, 'index'])
        ->name('notifications.index');
    Route::patch('settings/notifications', [NotificationPreferenceController::class, 'update'])
        ->name('notifications.update');
    Route::patch('settings/notifications/budgets/{budget}', [NotificationPreferenceController::class, 'updateBudget'])
        ->name('notifications.budgets.update');
    Route::patch('settings/notifications/savings-goals/{savingsGoal}', [NotificationPreferenceController::class, 'updateSavingsGoal'])
        ->name('notifications.savings-goals.update');

    Route::get('settings/banks', [BankController::class, 'index'])->name('banks.index');
    Route::post('settings/banks', [BankController::class, 'store'])->name('banks.store');

    Route::get('settings/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('settings/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('settings/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('settings/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('settings/labels', [LabelController::class, 'index'])->name('labels.index');
    Route::post('settings/labels', [LabelController::class, 'store'])->name('labels.store');
    Route::patch('settings/labels/{label}', [LabelController::class, 'update'])->name('labels.update');
    Route::delete('settings/labels/{label}', [LabelController::class, 'destroy'])->name('labels.destroy');

    // Only `block-demo` here: the press account is meant to hand out MCP
    // tokens, and the demo account is refused by the tools themselves anyway.
    Route::get('settings/mcp', [McpTokenController::class, 'index'])
        ->middleware('deny-framing')
        ->name('mcp.index');
    Route::post('settings/mcp/tokens', [McpTokenController::class, 'store'])
        ->middleware('block-demo')
        ->name('mcp.tokens.store');
    Route::post('settings/mcp/tokens/{token}/rotate', [McpTokenController::class, 'rotate'])
        ->middleware('block-demo')
        ->name('mcp.tokens.rotate');
    Route::delete('settings/mcp/tokens/{token}', [McpTokenController::class, 'destroy'])
        ->middleware('block-demo')
        ->name('mcp.tokens.destroy');
    Route::delete('settings/mcp/apps/{client}', [McpTokenController::class, 'destroyApp'])
        ->middleware('block-demo')
        ->name('mcp.apps.destroy');

    // `block-shared`: the API refuses both shared accounts (EnsureApiAccess),
    // so neither may hold an API token.
    Route::get('settings/api', [ApiTokenController::class, 'index'])
        ->middleware('deny-framing')
        ->name('api-tokens.index');
    Route::post('settings/api/tokens', [ApiTokenController::class, 'store'])
        ->middleware('block-shared')
        ->name('api-tokens.store');
    Route::post('settings/api/tokens/{token}/rotate', [ApiTokenController::class, 'rotate'])
        ->middleware('block-shared')
        ->name('api-tokens.rotate');
    Route::delete('settings/api/tokens/{token}', [ApiTokenController::class, 'destroy'])
        ->middleware('block-shared')
        ->name('api-tokens.destroy');
    Route::delete('settings/api/apps/{client}', [ApiTokenController::class, 'destroyApp'])
        ->middleware('block-shared')
        ->name('api-tokens.apps.destroy');

    Route::redirect('settings/budgets', '/budgets')->name('budgets.settings');

    Route::get('settings/automation-rules', [AutomationRuleController::class, 'index'])->name('automation-rules.index');
    Route::post('settings/automation-rules', [AutomationRuleController::class, 'store'])->name('automation-rules.store');
    Route::patch('settings/automation-rules/{automationRule}', [AutomationRuleController::class, 'update'])->name('automation-rules.update');
    Route::delete('settings/automation-rules/{automationRule}', [AutomationRuleController::class, 'destroy'])->name('automation-rules.destroy');

    Route::get('settings/automation-rules/{automationRule}/matches', [AutomationRuleApplicationController::class, 'matches'])
        ->name('automation-rules.matches');
    Route::post('settings/automation-rules/{automationRule}/apply', [AutomationRuleApplicationController::class, 'apply'])
        ->name('automation-rules.apply');
    Route::get('settings/automation-rules/apply/status/{jobId}', [AutomationRuleApplicationController::class, 'status'])
        ->name('automation-rules.apply.status');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::patch('settings/chart-color-scheme', [ChartColorSchemeController::class, 'update'])
        ->name('chart-color-scheme.update');

    Route::patch('settings/net-worth-chart-loan-preference', [NetWorthChartLoanPreferenceController::class, 'update'])
        ->name('net-worth-chart-loan-preference.update');

    Route::patch('settings/net-worth-chart-real-estate-preference', [NetWorthChartRealEstatePreferenceController::class, 'update'])
        ->name('net-worth-chart-real-estate-preference.update');

    Route::get('settings/billing', [SubscriptionController::class, 'billing'])->name('settings.billing');
    Route::get('settings/billing/portal', [SubscriptionController::class, 'billingPortal'])->name('settings.billing.portal');
    Route::post('settings/billing/cancel-unpaid', [SubscriptionController::class, 'cancelUnpaid'])
        ->middleware(['throttle:6,1', 'block-shared'])
        ->name('settings.billing.cancel-unpaid');
    Route::post('settings/billing/resume', [SubscriptionController::class, 'resume'])
        ->middleware(['throttle:6,1', 'block-demo', 'block-shared'])
        ->name('settings.billing.resume');
    Route::post('settings/billing/pause/resume', [SubscriptionPauseController::class, 'resume'])
        ->middleware(['throttle:6,1', 'block-demo', 'block-shared'])
        ->name('settings.billing.pause.resume');
    Route::post('settings/billing/swap-to-standard', [SubscriptionController::class, 'swapToStandard'])
        ->middleware(['throttle:6,1', 'block-demo', 'block-shared'])
        ->name('settings.billing.swap-to-standard');

    // The in-app cancel flow: the survey first, then an offer or the cancellation.
    Route::middleware(['throttle:10,1', 'block-demo', 'block-shared'])
        ->prefix('settings/billing/cancellation')
        ->name('settings.billing.cancellation.')
        ->group(function () {
            Route::post('/', [SubscriptionCancellationController::class, 'store'])->name('store');
            Route::post('{cancellationSurveyResponse}/retention-coupon', [SubscriptionCancellationController::class, 'applyRetentionCoupon'])
                ->whereUuid('cancellationSurveyResponse')
                ->name('retention-coupon');
            Route::post('{cancellationSurveyResponse}/switch-to-yearly', [SubscriptionCancellationController::class, 'switchToYearly'])
                ->whereUuid('cancellationSurveyResponse')
                ->name('switch-to-yearly');
            Route::post('{cancellationSurveyResponse}/pause', [SubscriptionCancellationController::class, 'pause'])
                ->whereUuid('cancellationSurveyResponse')
                ->name('pause');
            Route::post('{cancellationSurveyResponse}/cancel', [SubscriptionCancellationController::class, 'cancel'])
                ->whereUuid('cancellationSurveyResponse')
                ->name('cancel');
        });

    Route::get('settings/delete-account', function (Request $request) {
        return Inertia::render('settings/delete-account', [
            'mustCancelSubscription' => $request->user()->mustCancelSubscriptionBeforeDeleting(),
            'cancelsUnpaidSubscription' => $request->user()->unpaidSubscription() !== null,
        ]);
    })->name('delete-account.edit');

    // Import from another app. Not behind `onboarded`, like the rest of this
    // file; the controller decides who sees it (User::canUseFullImport()).
    Route::get('settings/import', [FullImportController::class, 'index'])->name('full-import.index');
    Route::get('settings/import/new', [FullImportController::class, 'create'])->name('full-import.create');
    Route::delete('settings/import/{import}', [FullImportController::class, 'destroy'])
        ->middleware('block-shared')
        ->name('full-import.destroy');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');

    Route::get('settings/connections', [ConnectionController::class, 'index'])->name('settings.connections.index');
    Route::post('settings/connections/{connection}/sync', [ConnectionController::class, 'sync'])->name('settings.connections.sync');
    Route::patch('settings/connections/{connection}/credentials', [ConnectionController::class, 'updateCredentials'])->name('settings.connections.update-credentials');
    Route::delete('settings/connections/{connection}', [ConnectionController::class, 'destroy'])->name('settings.connections.destroy');
});

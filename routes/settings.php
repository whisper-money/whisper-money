<?php

use App\Http\Controllers\Settings\AccountController;
use App\Http\Controllers\Settings\CategoryController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TwoFactorAuthenticationController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', '/settings/account');

    Route::get('settings/account', [ProfileController::class, 'account'])->name('account.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::get('settings/password', [PasswordController::class, 'edit'])->name('user-password.edit');

    Route::put('settings/password', [PasswordController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::get('settings/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('settings/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('settings/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
    Route::delete('settings/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');

    Route::get('settings/categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('settings/categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('settings/categories/{category}', [CategoryController::class, 'update'])->name('categories.update');
    Route::delete('settings/categories/{category}', [CategoryController::class, 'destroy'])->name('categories.destroy');

    Route::get('settings/automation-rules', [\App\Http\Controllers\Settings\AutomationRuleController::class, 'index'])->name('automation-rules.index');
    Route::post('settings/automation-rules', [\App\Http\Controllers\Settings\AutomationRuleController::class, 'store'])->name('automation-rules.store');
    Route::patch('settings/automation-rules/{automationRule}', [\App\Http\Controllers\Settings\AutomationRuleController::class, 'update'])->name('automation-rules.update');
    Route::delete('settings/automation-rules/{automationRule}', [\App\Http\Controllers\Settings\AutomationRuleController::class, 'destroy'])->name('automation-rules.destroy');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance.edit');

    Route::get('settings/delete-account', function () {
        return Inertia::render('settings/delete-account');
    })->name('delete-account.edit');

    Route::get('settings/two-factor', [TwoFactorAuthenticationController::class, 'show'])
        ->name('two-factor.show');
});

<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\RobotsController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\SubscriptionController;
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

Route::middleware(['auth'])->group(function () {
    Route::get('setup-encryption', function () {
        return Inertia::render('auth/setup-encryption');
    })->name('setup-encryption');
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

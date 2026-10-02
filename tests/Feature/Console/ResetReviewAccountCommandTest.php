<?php

use App\Enums\PlanFeature;
use App\Enums\TransactionSource;
use App\Models\User;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

beforeEach(function () {
    config(['app.review' => [
        'email' => 'openai-review@whisper.money',
        'password' => 'secret-review-password',
    ]]);
});

test('demo:reset --review fails when the reviewer password is not configured', function () {
    config(['app.review.password' => null]);

    $this->artisan('demo:reset --review')->assertFailed();

    expect(User::where('email', 'openai-review@whisper.money')->exists())->toBeFalse();
});

test('demo:reset --review ignores DEMO_ENABLED', function () {
    config(['app.demo.enabled' => false, 'app.review.password' => null]);

    // Failing on the missing reviewer config (rather than reporting the demo as
    // disabled) is what proves the demo switch is not consulted.
    $this->artisan('demo:reset --review')->assertFailed();
});

test('demo:reset --review seeds the data the stored test cases ask about, ending today', function () {
    config(['subscriptions.enabled' => true]);

    // The production reviewer account is on the Spanish locale. Reseeded as-is,
    // its categories came out in Spanish and every English template was skipped.
    $existing = User::factory()->create(['email' => 'openai-review@whisper.money', 'locale' => 'es']);

    $this->artisan('demo:reset --review')->assertSuccessful();

    $user = User::where('email', 'openai-review@whisper.money')->sole();

    expect($user->id)->toBe($existing->id)
        ->and($user->locale)->toBe('en')
        ->and($user->currency_code)->toBe('EUR')
        ->and($user->canUseFeature(PlanFeature::McpAccess))->toBeTrue()
        ->and($user->hasSeededSubscription())->toBeTrue()
        ->and($user->isDemoAccount())->toBeFalse();

    // The stored cases log "a 45 euro" dinner and edit a charge "to 10 euros".
    expect($user->transactions()->where('currency_code', '!=', 'EUR')->exists())->toBeFalse();

    // "How much did I spend on groceries last month?" needs data in that month.
    expect($user->categories()->where('name', 'Groceries')->exists())->toBeTrue()
        ->and($user->transactions()->whereDate('transaction_date', '>=', now()->startOfMonth())->exists())->toBeTrue()
        ->and($user->transactions()->whereBetween('transaction_date', [
            now()->subMonthNoOverflow()->startOfMonth(),
            now()->subMonthNoOverflow()->endOfMonth(),
        ])->exists())->toBeTrue();

    // "My Uber charges are filed as Shopping — move them to Transport." A few
    // misfiled rides, so ChatGPT can move them all within one turn's tool calls.
    $shopping = $user->categories()->where('name', 'Shopping')->sole();
    $misfiled = $user->transactions()->where('description', 'Uber Ride')->where('category_id', $shopping->id)->count();
    expect($misfiled)->toBeGreaterThan(0)->toBeLessThanOrEqual(6);

    // "Log a 45 euro cash expense" has an account to land on.
    expect($user->accounts()->where('name', 'Cash')->exists())->toBeTrue();

    // "Change the amount of that Amazon charge from my bank account": whichever
    // Amazon charge the model picks has to be a locked, bank-imported one.
    expect($user->transactions()->where('description', 'Amazon.com Purchase')->exists())->toBeTrue()
        ->and($user->transactions()->where('source', '!=', TransactionSource::EnableBanking)->exists())->toBeFalse();
})->group('slow');

test('the reviewer account is reset daily once its password is configured', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'demo:reset --review'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('10 4 * * *')
        ->and($event->filtersPass(app()))->toBeTrue();

    config(['app.review.password' => null]);

    expect($event->filtersPass(app()))->toBeFalse();
});

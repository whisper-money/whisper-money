<?php

use App\Actions\Subscription\CancelSubscriptionNow;
use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Exception\ApiConnectionException;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'EUR',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
    expect($user->currency_code)->toBe('EUR');
});

test('profile accepts a newly supported primary currency', function (string $currency, array $extra) {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => $currency,
            ...$extra,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    expect($user->refresh()->currency_code)->toBe($currency);
})->with([
    'Argentine peso (latam)' => ['ARS', []],
    'Pakistani rupee' => ['PKR', ['month_start_day' => 1]],
    'Brazilian real' => ['BRL', ['month_start_day' => 1]],
    'Saudi riyal' => ['SAR', ['month_start_day' => 1]],
]);

test('profile rejects bitcoin as primary currency', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'BTC',
        ]);

    $response->assertSessionHasErrors(['currency_code']);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
            'currency_code' => $user->currency_code,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('account.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('user can delete their account', function () {
    $this->travelTo(now()->setDate(2026, 4, 22)->setTime(10, 51, 24));

    $user = User::factory()->create();

    $originalEmail = $user->email;

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $deletedUser = User::withTrashed()->find($user->id);

    $this->assertGuest();
    expect(User::query()->find($user->id))->toBeNull();
    expect($deletedUser?->deleted_at)->not->toBeNull();
    expect($deletedUser?->email)->toBe('20260422105124_'.$originalEmail);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});

test('user with a trial or a paid-up subscription cannot delete their account', function (string $status, array $extra) {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}_delete_test",
        'stripe_status' => $status,
        'stripe_price' => 'price_delete_test',
        ...$extra,
    ]);

    $response = $this
        ->actingAs($user)
        ->from(route('delete-account.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasErrors('subscription')
        ->assertRedirect(route('delete-account.edit'));

    expect(User::query()->find($user->id))->not->toBeNull();
})->with([
    'active' => ['active', []],
    'on a trial' => ['trialing', fn () => ['trial_ends_at' => now()->addDays(7)]],
]);

test('deleting the account cancels a subscription whose payment failed', function (string $status) {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}_delete_test",
        'stripe_status' => $status,
        'stripe_price' => 'price_delete_test',
        'trial_ends_at' => now()->subDays(3),
    ]);

    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldReceive('handle')
        ->once()
        ->withArgs(fn (User $owner, Subscription $cancelled): bool => $owner->is($user) && $cancelled->is($subscription));
    app()->instance(CancelSubscriptionNow::class, $cancel);

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::query()->find($user->id))->toBeNull();
})->with([
    'past due' => ['past_due'],
    'unpaid' => ['unpaid'],
    'incomplete' => ['incomplete'],
]);

test('an unpaid subscription without a Stripe customer is cancelled locally on deletion', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create(['stripe_id' => null]);

    $subscription = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unpaid_local_cancel_test',
        'stripe_status' => 'unpaid',
        'stripe_price' => 'price_delete_test',
    ]);

    $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect($subscription->fresh())
        ->stripe_status->toBe('canceled')
        ->ends_at->not->toBeNull();
});

test('the account is kept when Stripe cannot cancel the unpaid subscription', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unpaid_stripe_down_test',
        'stripe_status' => 'unpaid',
        'stripe_price' => 'price_delete_test',
    ]);

    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldReceive('handle')->once()->andThrow(ApiConnectionException::factory('Stripe is down'));
    app()->instance(CancelSubscriptionNow::class, $cancel);

    $this
        ->actingAs($user)
        ->from(route('delete-account.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasErrors('subscription')
        ->assertRedirect(route('delete-account.edit'));

    expect(User::query()->find($user->id))->not->toBeNull();
});

test('a wrong password leaves the unpaid subscription alone', function () {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_unpaid_wrong_password_test',
        'stripe_status' => 'unpaid',
        'stripe_price' => 'price_delete_test',
    ]);

    $cancel = Mockery::mock(CancelSubscriptionNow::class);
    $cancel->shouldNotReceive('handle');
    app()->instance(CancelSubscriptionNow::class, $cancel);

    $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ])
        ->assertSessionHasErrors('password');

    expect(User::query()->find($user->id))->not->toBeNull();
});

test('user with a subscription Stripe cannot collect on can delete their account', function (string $status, bool $cancelled) {
    config(['subscriptions.enabled' => true]);

    $user = User::factory()->create();

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => "sub_{$status}_delete_test",
        'stripe_status' => $status,
        'stripe_price' => 'price_delete_test',
        'ends_at' => $cancelled ? now()->addDays(7) : null,
    ]);

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    expect(User::query()->find($user->id))->toBeNull();
})->with([
    'cancelled, still on grace period' => ['active', true],
    'checkout that never completed' => ['incomplete_expired', false],
    'canceled with no end date' => ['canceled', false],
    'paused, so nothing to collect' => ['paused', false],
]);

test('the reader can pick the region their amounts are written in', function () {
    $user = User::factory()->create(['format_locale' => 'es-ES']);

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'EUR',
            'format_locale' => 'es-MX',
        ]);

    $response->assertSessionHasNoErrors();

    expect($user->refresh()->format_locale)->toBe('es-MX');
});

test('a region the app does not offer is rejected rather than stored', function (string $region) {
    // An unknown tag is not cosmetic: a malformed one makes every `Intl`
    // constructor throw and takes the whole screen with it, so the list is
    // closed on the way in.
    $user = User::factory()->create(['format_locale' => 'es-ES']);

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'currency_code' => 'EUR',
            'format_locale' => $region,
        ]);

    $response->assertSessionHasErrors('format_locale');

    expect($user->refresh()->format_locale)->toBe('es-ES');
})->with(['es_MX', 'nb-NO', 'not a locale', 'es']);

test('the settings page is handed every region it has to draw', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('account.edit'))
        ->assertInertia(fn ($page) => $page
            ->where('formatLocales', fn ($locales): bool => collect($locales)
                ->intersect(['es-MX', 'es-419', 'en-US'])
                ->count() === 3)
        );
});

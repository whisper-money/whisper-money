<?php

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.second_offer' => true,
    ]);
});

/**
 * A free user who met the paywall a week ago with nothing imported.
 */
function freeUserPastThePaywall(): User
{
    return User::factory()->onboarded()->create(['paywall_seen_at' => now()->subWeek()]);
}

function giveTransactionCreatedAt(User $user, CarbonInterface $createdAt): Transaction
{
    $transaction = Transaction::factory()
        ->for($user)
        ->for(Account::factory()->for($user))
        ->create();

    $transaction->forceFill(['created_at' => $createdAt])->save();

    return $transaction;
}

test('a free user whose first transactions came after the paywall is eligible', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeTrue();
});

test('nobody is eligible while the flag is off', function () {
    config(['subscriptions.second_offer' => false]);
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
});

test('nobody is eligible while subscriptions are off', function () {
    config(['subscriptions.enabled' => false]);
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
});

test('a user without transactions is not eligible', function () {
    expect(freeUserPastThePaywall()->isEligibleForSecondOffer())->toBeFalse();
});

test('a user who never saw the paywall is not eligible', function () {
    $user = User::factory()->onboarded()->create(['paywall_seen_at' => null]);
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
});

test('a user who already had transactions when they saw the paywall is not eligible', function (string $offset) {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, $user->paywall_seen_at->copy()->modify($offset));
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
})->with([
    'before the paywall' => ['-1 hour'],
    'at the paywall' => ['+0 seconds'],
]);

test('a user ruled out for having had data at the paywall is not queried again', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, $user->paywall_seen_at->copy()->subHour());

    expect($user->isEligibleForSecondOffer())->toBeFalse();

    DB::enableQueryLog();
    expect($user->isEligibleForSecondOffer())->toBeFalse();

    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn (string $sql) => str_contains($sql, 'transactions')))->toBeEmpty();
});

test('a subscriber is not eligible', function () {
    $user = User::factory()->onboarded()->subscribed()->create(['paywall_seen_at' => now()->subWeek()]);
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
});

test('a former subscriber is not eligible', function () {
    $user = User::factory()->onboarded()->formerSubscriber()->create(['paywall_seen_at' => now()->subWeek()]);
    giveTransactionCreatedAt($user, now()->subDay());

    expect($user->isEligibleForSecondOffer())->toBeFalse();
});

test('the offer is only eligible until it has been shown', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    $user->markSecondOfferShown();

    expect($user->fresh()->isEligibleForSecondOffer())->toBeFalse();
});

test('the shown endpoint stamps the user once', function () {
    $user = freeUserPastThePaywall();

    actingAs($user)->post(route('subscribe.second-offer.shown'))->assertNoContent();

    $shownAt = $user->fresh()->second_offer_shown_at;
    expect($shownAt)->not->toBeNull();

    $this->travel(1)->day();
    actingAs($user)->post(route('subscribe.second-offer.shown'))->assertNoContent();

    expect($user->fresh()->second_offer_shown_at->equalTo($shownAt))->toBeTrue();
});

test('the shown endpoint requires a signed-in user', function () {
    $this->post(route('subscribe.second-offer.shown'))->assertRedirect();
});

test('the app shell is told to show the offer to an eligible user', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showSecondOffer', true));
});

test('the app shell is not told to show the offer once it was shown', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    actingAs($user)->post(route('subscribe.second-offer.shown'));

    actingAs($user->fresh())->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showSecondOffer', false));
});

test('the paywall never carries the offer', function () {
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    actingAs($user)->withoutVite()->get(route('subscribe'))
        ->assertInertia(fn (Assert $page) => $page->where('showSecondOffer', false));
});

test('the app shell is not told to show the offer while the flag is off', function () {
    config(['subscriptions.second_offer' => false]);
    $user = freeUserPastThePaywall();
    giveTransactionCreatedAt($user, now()->subDay());

    actingAs($user)->withoutVite()->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page->where('showSecondOffer', false));
});

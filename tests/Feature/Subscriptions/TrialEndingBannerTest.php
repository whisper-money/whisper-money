<?php

use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Subscriptions\TrialEndingBanner;
use App\Support\PriceTiers;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Cashier\Subscription;

use function Pest\Laravel\actingAs;

const TRIAL_BANNER_PRICE_ID = 'price_trial_banner_yearly';

beforeEach(function () {
    config(['subscriptions.enabled' => true, 'cashier.currency' => 'eur']);
    Carbon::setTestNow('2026-11-10 12:00:00');
    $this->withoutVite();

    // The price-to-lookup-key map SubscribedPlan would otherwise ask Stripe for.
    Cache::put('stripe_lookup_keys_by_price_id', [
        TRIAL_BANNER_PRICE_ID => PriceTiers::plansFor(PriceTiers::DEFAULT)['yearly']['stripe_lookup_key'],
    ]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An onboarded user in Madrid whose trial started a week ago and ends
 * `$daysLeft` days from today.
 *
 * @param  array<string, mixed>  $subscription
 */
function trialingUser(int $daysLeft, array $subscription = [], ?int $firstCharge = 3599): User
{
    $user = User::factory()->onboarded()->create(['timezone' => 'Europe/Madrid']);

    $created = $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_trial_banner_'.$user->id,
        'stripe_status' => 'trialing',
        'stripe_price' => TRIAL_BANNER_PRICE_ID,
        // Late afternoon in Madrid, so a trial ending today is still running.
        'trial_ends_at' => now()->addDays($daysLeft)->addHours(4),
        ...$subscription,
    ]);
    $created->forceFill(['created_at' => $subscription['created_at'] ?? now()->subWeek()])->save();

    // Stripe's preview of the invoice the trial ends with, as the banner caches it.
    Cache::put(TrialEndingBanner::FIRST_CHARGE_CACHE_PREFIX.$created->id, [
        'invoice' => $firstCharge === null ? null : ['amount' => $firstCharge, 'currency' => 'eur'],
    ]);

    return $user->fresh();
}

function syncedTransaction(User $user, ?Category $category = null): Transaction
{
    return Transaction::factory()
        ->enableBanking()
        ->for($user)
        ->for(Account::factory()->for($user))
        ->create(['category_id' => $category?->id]);
}

function trialBannerProp(User $user): mixed
{
    $banner = null;

    // Followed, so a user the app sends elsewhere (onboarding, the paywall) is
    // checked on the page they actually land on.
    actingAs($user)->followingRedirects()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$banner) {
            $banner = $page->toArray()['props']['trialEndingBanner'];
        });

    return $banner;
}

test('a trial at a threshold gets the banner with the first charge Stripe will take', function (int $daysLeft, int $threshold) {
    $user = trialingUser($daysLeft);
    $trialEndsOn = now()->addDays($daysLeft)->setTimezone('Europe/Madrid')->toDateString();

    expect(trialBannerProp($user))->toMatchArray([
        'daysLeft' => $daysLeft,
        'threshold' => $threshold,
        'trialEndsOn' => $trialEndsOn,
        'dismissalKey' => "{$trialEndsOn}:{$threshold}",
        'canceled' => false,
        // The invoice's amount, discounts included, not the list price.
        'charge' => ['amount' => 3599, 'currency' => 'EUR', 'period' => 'year'],
    ]);
})->with([
    'seven days out' => [7, 7],
    'between seven and three' => [5, 7],
    'three days out' => [3, 3],
    'the day before' => [1, 1],
    'the last day' => [0, 1],
]);

test('no banner before the first threshold', function () {
    expect(trialBannerProp(trialingUser(8)))->toBeNull();
});

test('a threshold the trial started inside is skipped, so a 7-day trial is first reminded at 3 days', function () {
    $user = trialingUser(7, ['created_at' => now()]);
    expect(trialBannerProp($user))->toBeNull();

    Carbon::setTestNow(now()->addDays(4));
    expect(trialBannerProp($user->fresh()))->toMatchArray(['daysLeft' => 3, 'threshold' => 3]);
});

test('no banner for a user who is not trialing', function (Closure $makeUser) {
    expect(trialBannerProp($makeUser()))->toBeNull();
})->with([
    'free user' => [fn () => User::factory()->onboarded()->create(['paywall_seen_at' => now()->subWeek()])],
    'paying subscriber' => [fn () => User::factory()->onboarded()->subscribed()->create()],
    'former subscriber' => [fn () => User::factory()->onboarded()->formerSubscriber()->create(['paywall_seen_at' => now()->subWeek()])],
]);

test('no banner while subscriptions are off', function () {
    $user = trialingUser(3);
    config(['subscriptions.enabled' => false]);

    expect(trialBannerProp($user))->toBeNull();
});

test('a user it does not apply to costs no query for it', function () {
    $user = User::factory()->onboarded()->subscribed()->create();
    syncedTransaction($user);

    DB::enableQueryLog();
    trialBannerProp($user->fresh());

    expect(collect(DB::getQueryLog())->pluck('query')->filter(
        fn (string $sql) => str_contains($sql, 'count(category_id)'),
    ))->toBeEmpty();
});

test('a trial set to cancel gets the banner without a charge, and nothing is counted for it', function () {
    $user = trialingUser(3, ['ends_at' => now()->addDays(4)]);
    syncedTransaction($user);
    syncedTransaction($user);

    expect(trialBannerProp($user))->toMatchArray([
        'canceled' => true,
        'charge' => null,
        'stats' => null,
        'threshold' => 3,
    ]);
});

test('an unknown charge leaves the amount out rather than guess it', function (array $subscription, ?int $firstCharge) {
    $user = trialingUser(3, $subscription, $firstCharge);

    expect(trialBannerProp($user))->toMatchArray(['canceled' => false, 'charge' => null]);
})->with([
    'a price we cannot match' => [['stripe_price' => 'price_nobody_knows'], 3599],
    'Stripe could not preview the invoice' => [[], null],
    'nothing due' => [[], 0],
]);

test('the stats count what the bank synced during the trial and how much is sorted', function () {
    $user = trialingUser(3);
    $category = Category::factory()->for($user)->create();

    syncedTransaction($user, $category);
    syncedTransaction($user, $category);
    syncedTransaction($user);
    // Entered by hand, and synced before the trial: neither is the plan's work.
    Transaction::factory()->for($user)->for(Account::factory()->for($user))->create();
    syncedTransaction($user)->forceFill(['created_at' => now()->subMonth()])->save();

    expect(trialBannerProp($user)['stats'])->toBe(['synced' => 3, 'categorizedPercent' => 66]);
});

test('a single synced movement makes no case, so no stats are sent', function () {
    $user = trialingUser(3);
    syncedTransaction($user);

    expect(trialBannerProp($user)['stats'])->toBeNull();
});

test('closing the banner hides it until the next threshold, on every device', function () {
    $user = trialingUser(5);
    $key = trialBannerProp($user)['dismissalKey'];

    actingAs($user)->post(route('trial-banner.dismiss'), ['dismissal_key' => $key])->assertNoContent();

    expect($user->fresh()->trial_banner_dismissed_for)->toBe(now()->addDays(5)->toDateString().':7')
        ->and(trialBannerProp($user->fresh()))->toBeNull();

    Carbon::setTestNow(now()->addDays(2));

    expect(trialBannerProp($user->fresh()))->toMatchArray(['threshold' => 3, 'daysLeft' => 3]);
});

test('a tab left open across a threshold cannot close the next banner unseen', function () {
    $user = trialingUser(4);
    $staleKey = trialBannerProp($user)['dismissalKey'];

    Carbon::setTestNow(now()->addDay());
    actingAs($user->fresh())->post(route('trial-banner.dismiss'), ['dismissal_key' => $staleKey])->assertNoContent();

    expect($user->fresh()->trial_banner_dismissed_for)->toBeNull()
        ->and(trialBannerProp($user->fresh()))->toMatchArray(['threshold' => 3]);
});

test('closing it outside a threshold records nothing', function () {
    $user = trialingUser(10);

    actingAs($user)->post(route('trial-banner.dismiss'), ['dismissal_key' => now()->addDays(10)->toDateString().':7'])->assertNoContent();

    expect($user->fresh()->trial_banner_dismissed_for)->toBeNull();
});

test('dismissing needs the key of the banner being closed', function () {
    actingAs(trialingUser(3))->postJson(route('trial-banner.dismiss'))->assertJsonValidationErrors('dismissal_key');
});

test('a guest cannot dismiss it', function () {
    $this->post(route('trial-banner.dismiss'))->assertRedirect();
});

test('the banner stays off the subscription screens and onboarding', function () {
    $user = trialingUser(3);
    $user->forceFill(['onboarded_at' => null])->save();

    expect(trialBannerProp($user->fresh()))->toBeNull();
});

test('the trial banner and the second offer never reach the same user', function () {
    config(['subscriptions.second_offer' => true]);
    $user = trialingUser(3);
    $user->forceFill(['paywall_seen_at' => now()->subWeek()])->save();
    syncedTransaction($user);

    actingAs($user->fresh())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('showSecondOffer', false)
            ->where('trialEndingBanner.threshold', 3));

    // And the other way round: the user the second offer is for has no trial.
    $free = User::factory()->onboarded()->create(['paywall_seen_at' => now()->subWeek()]);
    syncedTransaction($free);

    actingAs($free->fresh())->get(route('dashboard'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('showSecondOffer', true)
            ->where('trialEndingBanner', null));
});

test('a subscription with no trial gets no banner', function () {
    $user = trialingUser(3);
    Subscription::query()->where('user_id', $user->id)->update(['trial_ends_at' => null, 'stripe_status' => 'active']);

    expect(trialBannerProp($user->fresh()))->toBeNull();
});

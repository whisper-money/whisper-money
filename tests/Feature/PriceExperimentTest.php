<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use App\Services\Subscriptions\PriceExperiment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.price_experiment.started_at' => '2026-06-01',
        'subscriptions.price_experiment.force_variant' => null,
        'subscriptions.price_experiment.variants.high' => [
            'monthly' => ['price' => 8.99, 'original_price' => null, 'lookup' => 'whisper_pro_monthly_high'],
            'yearly' => ['price' => 53.94, 'original_price' => 107.88, 'lookup' => 'whisper_pro_yearly_high'],
        ],
        'subscriptions.plans.monthly.price' => 3.99,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'whisper_pro_monthly',
        'subscriptions.plans.yearly.price' => 23.88,
        'subscriptions.plans.yearly.stripe_lookup_key' => 'whisper_pro_yearly',
    ]);
});

it('treats a user with no stored arm as legacy', function () {
    $user = User::factory()->create(['price_arm' => null]);

    expect(PriceExperiment::armFor($user))->toBe(PriceExperiment::LEGACY);
});

it('ignores a stored arm that is not a real variant', function () {
    $user = User::factory()->create(['price_arm' => 'bogus']);

    expect(PriceExperiment::armFor($user))->toBe(PriceExperiment::LEGACY);
});

it('is not running while the experiment is off', function (?string $startedAt) {
    // An empty PRICE_EXPERIMENT_STARTED_AT in the env reads back as '', so an
    // ops typo must not launch the experiment and start charging the high price.
    config(['subscriptions.price_experiment.started_at' => $startedAt]);

    expect(PriceExperiment::isRunning())->toBeFalse();
})->with([null, '', '   ']);

it('stops drawing new visitors once a winner is forced', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);

    expect(PriceExperiment::isRunning())->toBeFalse();
});

it('pins everyone to the forced winner, visitors and users alike', function () {
    config(['subscriptions.price_experiment.force_variant' => PriceExperiment::HIGH]);

    $legacy = User::factory()->create(['price_arm' => null]);

    expect(PriceExperiment::armFor($legacy))->toBe(PriceExperiment::HIGH)
        ->and(PriceExperiment::armFor(null))->toBe(PriceExperiment::HIGH);
});

it('ignores an invalid forced variant', function () {
    config(['subscriptions.price_experiment.force_variant' => 'bogus']);

    expect(PriceExperiment::armFor(null))->toBe(PriceExperiment::LEGACY)
        ->and(PriceExperiment::isRunning())->toBeTrue();
});

it('draws both arms over enough visitors', function () {
    $draws = collect(range(1, 60))->map(fn () => PriceExperiment::draw());

    expect($draws->unique()->values()->all())->toEqualCanonicalizing([
        PriceExperiment::CONTROL,
        PriceExperiment::HIGH,
    ]);
});

it('applies the variant price and lookup key for a high-arm user', function () {
    $user = User::factory()->create(['price_arm' => PriceExperiment::HIGH]);

    $plans = PriceExperiment::plansFor($user);

    expect($plans['monthly']['price'])->toBe(8.99)
        ->and($plans['monthly']['stripe_lookup_key'])->toBe('whisper_pro_monthly_high')
        ->and($plans['yearly']['price'])->toBe(53.94)
        ->and($plans['yearly']['original_price'])->toBe(107.88)
        ->and(PriceExperiment::lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly_high')
        ->and(PriceExperiment::lookupKeyFor($user, 'yearly'))->toBe('whisper_pro_yearly_high');
});

it('leaves control-arm users on the config prices', function () {
    $user = User::factory()->create(['price_arm' => PriceExperiment::CONTROL]);

    expect(PriceExperiment::plansFor($user)['monthly']['price'])->toBe(3.99)
        ->and(PriceExperiment::lookupKeyFor($user, 'monthly'))->toBe('whisper_pro_monthly');
});

it('ignores the cookie for a signed-in user, so the arm cannot be edited', function () {
    $user = User::factory()->create(['price_arm' => PriceExperiment::HIGH]);

    expect(PriceExperiment::armFor($user, PriceExperiment::CONTROL))->toBe(PriceExperiment::HIGH);
});

it('quotes an anonymous visitor the price their cookie was drawn into', function () {
    $this->withCookie(PriceExperiment::COOKIE, PriceExperiment::HIGH)
        ->get('/')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('pricing.plans.monthly.price', 8.99)
            ->where('pricing.plans.yearly.price', 53.94));
});

it('quotes the control price on the very first visit when the draw lands on control', function () {
    // No cookie yet: the middleware draws one and must apply it to this same
    // response, since the landing is the first page that quotes a price.
    $this->get('/')
        ->assertOk()
        ->assertCookie(PriceExperiment::COOKIE)
        ->assertInertia(fn ($page) => $page
            ->where('pricing.plans.monthly.price', fn ($price) => in_array($price, [3.99, 8.99], true)));
});

it('does not draw an arm while the experiment is off', function () {
    config(['subscriptions.price_experiment.started_at' => null]);

    $this->get('/')
        ->assertOk()
        ->assertCookieMissing(PriceExperiment::COOKIE)
        ->assertInertia(fn ($page) => $page->where('pricing.plans.monthly.price', 3.99));
});

it('keeps the arm the visitor already has instead of redrawing it', function () {
    $this->withCookie(PriceExperiment::COOKIE, PriceExperiment::HIGH)
        ->get('/')
        ->assertOk()
        ->assertCookieMissing(PriceExperiment::COOKIE);
});

it('freezes the visitor arm onto the user at registration', function () {
    $this->withCookie(PriceExperiment::COOKIE, PriceExperiment::HIGH)
        ->post(route('register'), [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'password-1234',
            'password_confirmation' => 'password-1234',
        ]);

    expect(User::where('email', 'ada@example.com')->value('price_arm'))->toBe(PriceExperiment::HIGH);
});

it('registers without an arm when the visitor arrived with no cookie', function () {
    config(['subscriptions.price_experiment.started_at' => null]);

    $this->post(route('register'), [
        'name' => 'Grace',
        'email' => 'grace@example.com',
        'password' => 'password-1234',
        'password_confirmation' => 'password-1234',
    ]);

    expect(User::where('email', 'grace@example.com')->value('price_arm'))->toBeNull();
});

it('charges the stored arm price id at checkout, not a client-supplied one', function () {
    Cache::put('stripe_price_id:whisper_pro_monthly_high', 'price_high_monthly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class)->shouldIgnoreMissing();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('getAttribute')->with('price_arm')->andReturn(PriceExperiment::HIGH);
    $user->shouldReceive('newSubscription')
        ->once()
        ->with('default', 'price_high_monthly')
        ->andReturn($builder);

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    // The cookie says control; the stored arm must win.
    $this->withCookie(PriceExperiment::COOKIE, PriceExperiment::CONTROL)
        ->get(route('subscribe.checkout', ['plan' => 'monthly']))
        ->assertRedirect();
});

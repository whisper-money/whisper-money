<?php

declare(strict_types=1);

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Cache;
use Laravel\Cashier\Checkout;
use Laravel\Cashier\SubscriptionBuilder;

/**
 * `SUBSCRIPTIONS_DEFAULT_PLAN` is read once while the config file is evaluated,
 * so it is exercised by evaluating that file again with the environment set,
 * not by setting a config key a booted app would already be past.
 */
function defaultPlanWith(?string $value): string
{
    $key = 'SUBSCRIPTIONS_DEFAULT_PLAN';
    $previous = $_ENV[$key] ?? null;

    $set = static function (?string $value) use ($key): void {
        if ($value === null) {
            unset($_ENV[$key], $_SERVER[$key]);
            putenv($key);

            return;
        }

        $_ENV[$key] = $_SERVER[$key] = $value;
        putenv("{$key}={$value}");
    };

    $set($value);

    try {
        return (require config_path('subscriptions.php'))['default_plan'];
    } finally {
        $set($previous);
    }
}

it('preselects the yearly plan while the switch is absent', function () {
    expect(defaultPlanWith(null))->toBe('yearly');
});

it('preselects the plan the switch names', function (string $value, string $expected) {
    expect(defaultPlanWith($value))->toBe($expected);
})->with([
    'monthly' => ['monthly', 'monthly'],
    'yearly' => ['yearly', 'yearly'],
    'surrounding case and spaces' => [' Monthly ', 'monthly'],
]);

it('falls back to yearly when the switch names no plan', function (string $value) {
    expect(defaultPlanWith($value))->toBe('yearly');
})->with(['', 'weekly', 'lifetime', 'nonsense']);

it('shares the configured default plan with every page', function () {
    config(['subscriptions.default_plan' => 'monthly']);

    $this->get(route('home'))
        ->assertInertia(fn ($page) => $page->where('pricing.defaultPlan', 'monthly'));
});

/**
 * Mocks a checkout for the monthly plan, expecting the metadata given (or none
 * at all when null) to reach the Stripe subscription.
 *
 * @param  array<string, string>|null  $metadata
 */
function expectCheckoutMetadata(?array $metadata, string $priceId = 'price_test_monthly'): void
{
    config([
        'subscriptions.plans.monthly.trial_days' => 0,
        'subscriptions.plans.monthly.stripe_lookup_key' => 'test_monthly_lookup',
        'subscriptions.plans.yearly.trial_days' => 0,
        'subscriptions.plans.yearly.stripe_lookup_key' => 'test_yearly_lookup',
    ]);
    Cache::put('stripe_price_id:test_monthly_lookup', 'price_test_monthly', now()->addHour());
    Cache::put('stripe_price_id:test_yearly_lookup', 'price_test_yearly', now()->addHour());

    $checkout = Mockery::mock(Checkout::class);
    $checkout->shouldReceive('toResponse')->andReturn(new RedirectResponse('https://stripe.test/session'));

    $builder = Mockery::mock(SubscriptionBuilder::class);
    $builder->shouldReceive('allowPromotionCodes')->once()->andReturnSelf();
    $metadata === null
        ? $builder->shouldNotReceive('withMetadata')
        : $builder->shouldReceive('withMetadata')->once()->with($metadata)->andReturnSelf();
    $builder->shouldReceive('checkout')->once()->andReturn($checkout);

    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('hasVerifiedEmail')->andReturn(true);
    $user->shouldReceive('hasProPlan')->andReturn(false);
    $user->shouldReceive('newSubscription')->once()->with('default', $priceId)->andReturn($builder);

    test()->withoutMiddleware(HandleInertiaRequests::class);
    test()->actingAs($user);
}

test('checkout falls back to the configured default plan without a plan in the query', function () {
    config(['subscriptions.default_plan' => 'monthly']);
    expectCheckoutMetadata(null, 'price_test_monthly');

    $this->get(route('subscribe.checkout'))->assertRedirect();
});

test('checkout stamps the preselected plan in the stripe subscription metadata', function () {
    expectCheckoutMetadata(['preselected_plan' => 'yearly']);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly', 'preselected_plan' => 'yearly']))->assertRedirect();
});

test('checkout stamps the preselected plan next to the upsell source', function () {
    expectCheckoutMetadata(['upsell_source' => 'connections', 'preselected_plan' => 'monthly']);

    $this->get(route('subscribe.checkout', [
        'plan' => 'monthly',
        'source' => 'connections',
        'preselected_plan' => 'monthly',
    ]))->assertRedirect();
});

test('checkout attributes a subscription started from the API and MCP settings', function (string $source) {
    expectCheckoutMetadata(['upsell_source' => $source, 'preselected_plan' => 'monthly']);

    $this->get(route('subscribe.checkout', [
        'plan' => 'monthly',
        'source' => $source,
        'preselected_plan' => 'monthly',
    ]))->assertRedirect();
})->with(['api', 'mcp']);

test('checkout drops a preselected plan that is not one of the plans', function (string $value) {
    expectCheckoutMetadata(null);

    $this->get(route('subscribe.checkout', ['plan' => 'monthly', 'preselected_plan' => $value]))->assertRedirect();
})->with(['weekly', 'lifetime', 'trial_days', 'yearly.features', '']);

<?php

use App\Features\MonthlySummaries;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

/*
 * The flag reads `monthly_summary.enabled` so the rollout can be flipped from
 * the environment. Pennant only asks `resolve()` once per reader, so these go
 * through fresh readers with no stored value.
 */

it('is off by default', function (): void {
    expect(config('monthly_summary.enabled'))->toBeFalse();
});

it('resolves off when the config says so', function (): void {
    config()->set('monthly_summary.enabled', false);

    expect(Feature::for(User::factory()->create())->active(MonthlySummaries::class))->toBeFalse();
});

it('resolves on when the config says so', function (): void {
    config()->set('monthly_summary.enabled', true);

    expect(Feature::for(User::factory()->create())->active(MonthlySummaries::class))->toBeTrue();
});

/*
 * The caveat the docblock warns about, and the command it points at. Pinned
 * because the command only accepts the short class name: the fully qualified
 * one resolves to App\Features\App\Features\... and quietly fails.
 */

it('leaves a stored value alone when the config flips', function (): void {
    config()->set('monthly_summary.enabled', false);

    $user = User::factory()->create();
    Feature::for($user)->active(MonthlySummaries::class);

    config()->set('monthly_summary.enabled', true);
    Feature::flushCache();

    expect(Feature::for($user)->active(MonthlySummaries::class))->toBeFalse();
});

it('moves the stored value with the documented command', function (): void {
    config()->set('monthly_summary.enabled', false);

    $user = User::factory()->create();
    Feature::for($user)->active(MonthlySummaries::class);

    expect(Artisan::call('feature:enable', ['feature' => 'MonthlySummaries', 'target' => 'all']))->toBe(0);

    Feature::flushCache();

    expect(Feature::for($user)->active(MonthlySummaries::class))->toBeTrue();
});

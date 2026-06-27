<?php

use App\Features\SubscriptionExperiment;
use App\Models\User;
use App\Services\Subscriptions\ExperimentOffer;
use Carbon\CarbonImmutable;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config([
        'subscriptions.enabled' => true,
        'subscriptions.experiment.started_at' => '2026-06-01',
        'subscriptions.experiment.reduced_trial.monthly' => 3,
        'subscriptions.experiment.reduced_trial.yearly' => 7,
        'subscriptions.experiment.pay_now_refund_window_days' => 3,
        'subscriptions.plans.monthly.trial_days' => 15,
        'subscriptions.plans.yearly.trial_days' => 15,
    ]);
});

it('keeps users who registered before the experiment as legacy', function () {
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-20')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('treats a null (guest) scope as legacy', function () {
    expect((new SubscriptionExperiment)->resolve(null))->toBe(SubscriptionExperiment::LEGACY);
});

it('treats everyone as legacy while the experiment is off', function () {
    config(['subscriptions.experiment.started_at' => null]);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('pins every user to the forced winner variant', function () {
    config(['subscriptions.experiment.force_variant' => SubscriptionExperiment::PAY_NOW]);
    $offer = app(ExperimentOffer::class);

    $legacy = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);
    $fresh = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    expect($offer->variantFor($legacy))->toBe(SubscriptionExperiment::PAY_NOW)
        ->and($offer->variantFor($fresh))->toBe(SubscriptionExperiment::PAY_NOW)
        ->and($offer->trialDaysFor($fresh, 'monthly'))->toBe(0);
});

it('ignores an invalid forced variant', function () {
    config(['subscriptions.experiment.force_variant' => 'bogus']);

    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-05-01')]);

    expect(app(ExperimentOffer::class)->variantFor($user))->toBe(SubscriptionExperiment::LEGACY);
});

it('splits post-start users across all three variants and stays stable per user', function () {
    $offer = app(ExperimentOffer::class);
    $variants = [];

    for ($i = 0; $i < 60; $i++) {
        $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
        $assigned = $offer->variantFor($user);
        $variants[] = $assigned;

        Feature::flushCache();
        expect($offer->variantFor($user))->toBe($assigned);
    }

    expect(array_unique($variants))->toEqualCanonicalizing([
        SubscriptionExperiment::CONTROL,
        SubscriptionExperiment::REDUCED_TRIAL,
        SubscriptionExperiment::PAY_NOW,
    ]);
});

it('applies the trial days that match each variant', function () {
    $offer = app(ExperimentOffer::class);
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);

    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::CONTROL);
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(15)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(15);

    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::REDUCED_TRIAL);
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(3)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(7);

    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::PAY_NOW);
    expect($offer->trialDaysFor($user, 'monthly'))->toBe(0)
        ->and($offer->trialDaysFor($user, 'yearly'))->toBe(0);
});

it('exposes the experiment offer on the paywall', function () {
    $user = User::factory()->onboarded()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::REDUCED_TRIAL);

    $this->actingAs($user)
        ->get(route('subscribe'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('subscription/paywall')
            ->where('offer.variant', SubscriptionExperiment::REDUCED_TRIAL)
            ->where('offer.payNow', false)
            ->where('offer.trialDays.monthly', 3)
            ->where('offer.trialDays.yearly', 7));
});

it('describes a pay-now offer for the frontend', function () {
    $user = User::factory()->create(['created_at' => CarbonImmutable::parse('2026-06-10')]);
    Feature::for($user)->activate(SubscriptionExperiment::class, SubscriptionExperiment::PAY_NOW);

    $offer = app(ExperimentOffer::class)->offerFor($user);

    expect($offer['variant'])->toBe(SubscriptionExperiment::PAY_NOW)
        ->and($offer['payNow'])->toBeTrue()
        ->and($offer['refundWindowDays'])->toBe(3)
        ->and($offer['trialDays']['monthly'])->toBe(0)
        ->and($offer['trialDays']['yearly'])->toBe(0);
});

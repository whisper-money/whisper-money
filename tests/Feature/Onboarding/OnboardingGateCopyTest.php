<?php

use App\Features\OnboardingGateCopy;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Laravel\Pennant\Feature;

beforeEach(function () {
    config(['subscriptions.enabled' => true]);
});

it('draws every user into one of the two trial arms, about half each', function () {
    $users = User::factory()->count(300)->create();

    $counts = $users
        ->map(fn (User $user) => OnboardingGateCopy::armFor($user))
        ->countBy();

    expect($counts->keys()->sort()->values()->all())
        ->toBe(collect(OnboardingGateCopy::ARMS)->sort()->values()->all());

    foreach (OnboardingGateCopy::ARMS as $arm) {
        expect($counts[$arm])->toBeGreaterThan(120)->toBeLessThan(180);
    }
});

it('keeps a user in the same arm across reads and cache flushes', function () {
    $user = User::factory()->create();

    $first = OnboardingGateCopy::armFor($user);
    Feature::flushCache();

    expect(OnboardingGateCopy::armFor($user))->toBe($first);
});

it('stores the arm so trial-to-paid can be joined against it', function () {
    $user = User::factory()->create();

    $arm = OnboardingGateCopy::armFor($user);

    $this->assertDatabaseHas('features', [
        'name' => OnboardingGateCopy::class,
        'scope' => Feature::serializeScope($user),
        'value' => json_encode($arm),
    ]);
});

it('hands the arm to the onboarding page', function () {
    $user = User::factory()->create(['onboarded_at' => null]);

    $this->actingAs($user)->get('/onboarding')
        ->assertInertia(fn ($page) => $page
            ->where('gateCopyArm', OnboardingGateCopy::armFor($user)));
});

it('draws no arm where the gate cannot reach', function (array $config, ?string $signupPlan) {
    config($config);
    $user = User::factory()->create(['onboarded_at' => null, 'signup_plan' => $signupPlan]);

    $this->actingAs($user)->get('/onboarding')
        ->assertInertia(fn ($page) => $page->where('gateCopyArm', null));

    $this->assertDatabaseMissing('features', ['name' => OnboardingGateCopy::class]);
})->with([
    'subscriptions off' => [['subscriptions.enabled' => false], null],
    'free-plan signup' => [[], 'free'],
]);

it('sends everybody to control, with no arm, once disabled for all', function () {
    $assigned = User::factory()->create();
    OnboardingGateCopy::armFor($assigned);

    Artisan::call('feature:disable', ['feature' => 'OnboardingGateCopy', 'target' => 'all']);
    Feature::flushCache();

    $newcomer = User::factory()->create();

    expect(OnboardingGateCopy::armFor($assigned))->toBeNull()
        ->and(OnboardingGateCopy::armFor($newcomer))->toBeNull();
});

it('puts everybody back in their own arm when enabled again', function () {
    $user = User::factory()->create();
    $arm = OnboardingGateCopy::armFor($user);

    Artisan::call('feature:disable', ['feature' => 'OnboardingGateCopy', 'target' => 'all']);
    Artisan::call('feature:enable', ['feature' => 'OnboardingGateCopy', 'target' => 'all']);
    Feature::flushCache();

    expect(OnboardingGateCopy::armFor($user))->toBe($arm);
});

it('keeps a user already stored in a trial arm there, whatever the hash says', function (string $storedArm) {
    $user = User::factory()->create();
    Feature::for($user)->activate(OnboardingGateCopy::class, $storedArm);
    Feature::flushCache();

    expect(OnboardingGateCopy::armFor($user))->toBe($storedArm);
})->with(OnboardingGateCopy::ARMS);

it('reads a user stored in the dropped control arm as out of the experiment', function () {
    $user = User::factory()->create(['onboarded_at' => null]);
    Feature::for($user)->activate(OnboardingGateCopy::class, 'control');

    expect(OnboardingGateCopy::armFor($user))->toBeNull();

    $this->actingAs($user)->get('/onboarding')
        ->assertInertia(fn ($page) => $page->where('gateCopyArm', null));
});

it('ignores a stored value that is not an arm', function () {
    $user = User::factory()->create();
    Feature::for($user)->activate(OnboardingGateCopy::class, 'cheap');

    expect(OnboardingGateCopy::armFor($user))->toBeNull();
});

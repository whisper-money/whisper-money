<?php

use App\Enums\LeadCohort;
use App\Models\UserLead;
use App\Services\LeadCohortResolver;
use Illuminate\Database\Eloquent\Factories\Sequence;

beforeEach(function (): void {
    $this->resolver = app(LeadCohortResolver::class);
});

it('returns null for leads without a positive position', function (): void {
    $lead = UserLead::factory()->unverified()->create();

    expect($this->resolver->resolve($lead))->toBeNull();
});

it('classifies the first ten ranked leads as founders', function (): void {
    $leads = collect(range(1, 12))->map(
        fn (int $position) => UserLead::factory()->ranked(500 + $position)->create(),
    );

    expect($this->resolver->resolve($leads[0]))->toBe(LeadCohort::Founder);
    expect($this->resolver->resolve($leads[9]))->toBe(LeadCohort::Founder);
    expect($this->resolver->resolve($leads[10]))->toBe(LeadCohort::EarlyBird);
});

it('classifies ranks 11-100 as early bird', function (): void {
    UserLead::factory()->count(10)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 10)),
    ))->create();

    $eleventh = UserLead::factory()->ranked(11)->create();
    $hundredth = UserLead::factory()->ranked(100)->create();

    UserLead::factory()->count(88)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(12, 99)),
    ))->create();

    expect($this->resolver->resolve($eleventh))->toBe(LeadCohort::EarlyBird);
    expect($this->resolver->resolve($hundredth))->toBe(LeadCohort::EarlyBird);
});

it('classifies ranks 101+ as waitlist', function (): void {
    UserLead::factory()->count(100)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 100)),
    ))->create();

    $lead = UserLead::factory()->ranked(101)->create();

    expect($this->resolver->resolve($lead))->toBe(LeadCohort::Waitlist);
});

it('marks referrers of founders as founder_referrer regardless of own rank', function (): void {
    $referrer = UserLead::factory()->ranked(750)->create();

    UserLead::factory()->ranked(1)->create(['referred_by_id' => $referrer->id]);
    UserLead::factory()->count(9)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(2, 10)),
    ))->create();

    expect($this->resolver->resolve($referrer))->toBe(LeadCohort::FounderReferrer);
});

<?php

use App\Enums\LeadCohort;
use App\Mail\UserLeadInvitation;
use App\Models\UserLead;
use App\Services\LeadPromoCodeAllocator;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();

    $this->app->instance(LeadPromoCodeAllocator::class, new class extends LeadPromoCodeAllocator
    {
        public function ensureCodes(UserLead $lead, LeadCohort $cohort): void
        {
            $lead->forceFill([
                'promo_code_monthly' => $lead->promo_code_monthly ?? 'WM-FAKE-M-'.substr($lead->id, 0, 4),
                'promo_code_yearly' => $lead->promo_code_yearly ?? 'WM-FAKE-Y-'.substr($lead->id, 0, 4),
            ])->save();
        }
    });
});

it('keeps the previous invite command aliases available', function (string $command): void {
    $this->artisan($command, ['--dry-run' => true])
        ->expectsOutput('No pending leads found.')
        ->assertSuccessful();
})->with([
    'singular alias' => 'leads:send-invite',
    'plural alias' => 'leads:send-invites',
]);

it('sends invitations to the next batch ordered by position', function (): void {
    UserLead::factory()->count(15)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 15)),
    ))->create();

    $this->artisan('leads:send-invitations', ['--limit' => 5, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadInvitation::class, 5);

    expect(UserLead::query()->whereNotNull('invitation_sent_at')->count())->toBe(5);

    $invited = UserLead::query()->whereNotNull('invitation_sent_at')->orderBy('position')->pluck('position')->all();
    expect($invited)->toBe([1, 2, 3, 4, 5]);
});

it('skips already-invited leads on subsequent runs', function (): void {
    UserLead::factory()->count(6)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 6)),
    ))->create();

    UserLead::query()->where('position', 1)->update(['invitation_sent_at' => now()]);

    $this->artisan('leads:send-invitations', ['--limit' => 3, '--force' => true])
        ->assertSuccessful();

    $invited = UserLead::query()->whereNotNull('invitation_sent_at')->orderBy('position')->pluck('position')->all();
    expect($invited)->toBe([1, 2, 3, 4]);
});

it('ignores leads with null or zero position', function (): void {
    UserLead::factory()->ranked(1)->create();
    UserLead::factory()->unverified()->create();

    UserLead::withoutEvents(function (): void {
        UserLead::factory()->create(['position' => 0, 'email_verified_at' => now()]);
    });

    $this->artisan('leads:send-invitations', ['--limit' => 50, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadInvitation::class, 1);
});

it('records the cohort on each invited lead', function (): void {
    UserLead::factory()->count(12)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 12)),
    ))->create();

    $this->artisan('leads:send-invitations', ['--limit' => 12, '--force' => true])
        ->assertSuccessful();

    $cohorts = UserLead::query()->orderBy('position')->pluck('cohort')->all();

    expect($cohorts[0])->toBe(LeadCohort::Founder);
    expect($cohorts[9])->toBe(LeadCohort::Founder);
    expect($cohorts[10])->toBe(LeadCohort::EarlyBird);
});

it('sends an invitation to a specific pending lead by email', function (): void {
    UserLead::factory()->count(12)->state(new Sequence(
        ...array_map(fn (int $i) => ['position' => $i, 'email_verified_at' => now()], range(1, 12)),
    ))->create();

    $target = UserLead::factory()->ranked(42)->create(['email' => 'target@example.com']);

    $this->artisan('leads:send-invitations', ['--email' => 'target@example.com', '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadInvitation::class, 1);
    Mail::assertQueued(UserLeadInvitation::class, fn (UserLeadInvitation $mail): bool => $mail->hasTo('target@example.com'));

    expect($target->refresh()->invitation_sent_at)->not->toBeNull()
        ->and(UserLead::query()->whereNotNull('invitation_sent_at')->pluck('email')->all())->toBe(['target@example.com']);
});

it('fails when the requested email is not a pending lead', function (): void {
    UserLead::factory()->ranked(1)->create(['email' => 'already@example.com', 'invitation_sent_at' => now()]);

    $this->artisan('leads:send-invitations', ['--email' => 'already@example.com', '--force' => true])
        ->expectsOutput('No pending lead found for already@example.com.')
        ->assertFailed();

    Mail::assertNothingQueued();
});

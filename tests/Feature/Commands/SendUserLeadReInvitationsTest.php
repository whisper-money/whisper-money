<?php

use App\Mail\UserLeadReInvitation;
use App\Models\User;
use App\Models\UserLead;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Illuminate\Support\Facades\Mail;

beforeEach(function (): void {
    Mail::fake();
});

it('sends re-invitations to invited leads who have not signed up', function (): void {
    UserLead::factory()->count(3)->state(new Sequence(
        ['email' => 'first@example.com', 'invitation_sent_at' => now()->subDays(5)],
        ['email' => 'second@example.com', 'invitation_sent_at' => now()->subDays(4)],
        ['email' => 'third@example.com', 'invitation_sent_at' => now()->subDays(3)],
    ))->create();

    $this->artisan('leads:send-re-invitations', ['--limit' => 2, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadReInvitation::class, 2);

    expect(UserLead::query()->whereNotNull('re_invitation_sent_at')->count())->toBe(2)
        ->and(UserLead::query()->whereNotNull('re_invitation_sent_at')->sum('re_invitation_count'))->toBe('2');

    $emails = UserLead::query()->whereNotNull('re_invitation_sent_at')->orderBy('invitation_sent_at')->pluck('email')->all();
    expect($emails)->toBe(['first@example.com', 'second@example.com']);
});

it('skips leads that already signed up or were already re-invited', function (): void {
    UserLead::factory()->create(['email' => 'pending@example.com', 'invitation_sent_at' => now()->subDays(3)]);
    UserLead::factory()->create(['email' => 'signed-up@example.com', 'invitation_sent_at' => now()->subDays(3)]);
    UserLead::factory()->create([
        'email' => 'already-reinvited@example.com',
        'invitation_sent_at' => now()->subDays(3),
        're_invitation_sent_at' => now()->subDay(),
        're_invitation_count' => 1,
    ]);
    User::factory()->create(['email' => 'signed-up@example.com']);

    $this->artisan('leads:send-re-invitations', ['--limit' => 10, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadReInvitation::class, 1);
    Mail::assertQueued(UserLeadReInvitation::class, fn (UserLeadReInvitation $mail): bool => $mail->hasTo('pending@example.com'));
});

it('can re-invite a specific email', function (): void {
    $target = UserLead::factory()->create(['email' => 'target@example.com', 'invitation_sent_at' => now()->subDays(3)]);
    UserLead::factory()->create(['email' => 'other@example.com', 'invitation_sent_at' => now()->subDays(3)]);

    $this->artisan('leads:send-re-invitations', ['--email' => 'target@example.com', '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadReInvitation::class, 1);
    Mail::assertQueued(UserLeadReInvitation::class, fn (UserLeadReInvitation $mail): bool => $mail->hasTo('target@example.com'));

    expect($target->refresh()->re_invitation_sent_at)->not->toBeNull()
        ->and(UserLead::query()->whereNotNull('re_invitation_sent_at')->pluck('email')->all())->toBe(['target@example.com']);
});

it('shows re-invitation signup stats', function (): void {
    UserLead::factory()->create([
        'email' => 'converted@example.com',
        'invitation_sent_at' => now()->subDays(4),
        're_invitation_sent_at' => now()->subDays(2),
        're_invitation_count' => 1,
    ]);
    UserLead::factory()->create([
        'email' => 'not-converted@example.com',
        'invitation_sent_at' => now()->subDays(4),
        're_invitation_sent_at' => now()->subDays(2),
        're_invitation_count' => 1,
    ]);
    User::factory()->create(['email' => 'converted@example.com', 'created_at' => now()->subDay()]);

    $this->artisan('leads:send-re-invitations', ['--stats' => true])
        ->expectsTable(['Metric', 'Value'], [
            ['Re-invited leads', 2],
            ['Re-invited leads signed up', 1],
            ['Success rate', '50%'],
        ])
        ->assertSuccessful();

    Mail::assertNothingQueued();
});

it('defaults to leads invited at least three days ago', function (): void {
    UserLead::factory()->create(['email' => 'old@example.com', 'invitation_sent_at' => now()->subDays(3)]);
    UserLead::factory()->create(['email' => 'fresh@example.com', 'invitation_sent_at' => now()->subDays(2)]);

    $this->artisan('leads:send-re-invitations', ['--limit' => 10, '--force' => true])
        ->assertSuccessful();

    Mail::assertQueued(UserLeadReInvitation::class, 1);
    Mail::assertQueued(UserLeadReInvitation::class, fn (UserLeadReInvitation $mail): bool => $mail->hasTo('old@example.com'));
});

it('respects the minimum days since original invite filter', function (): void {
    UserLead::factory()->create(['email' => 'old@example.com', 'invitation_sent_at' => now()->subDays(10)]);
    UserLead::factory()->create(['email' => 'fresh@example.com', 'invitation_sent_at' => now()->subDays(2)]);

    $this->artisan('leads:send-re-invitations', [
        '--limit' => 10,
        '--min-days-since-invite' => 7,
        '--force' => true,
    ])->assertSuccessful();

    Mail::assertQueued(UserLeadReInvitation::class, 1);
    Mail::assertQueued(UserLeadReInvitation::class, fn (UserLeadReInvitation $mail): bool => $mail->hasTo('old@example.com'));
});

<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveNoBankEmailJob;
use App\Jobs\Drip\SendMonthlySummaryReminderEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Mail\Drip\WelcomeEmail;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

/*
 * The shared cooldown between nudge emails.
 *
 * The manual-only reader is the audience of nearly every nudge in the app —
 * `inactive_no_bank` fires seven days after their last visit and the monthly
 * reminder fires on the 3rd, so they collide by design. Whichever arrives first
 * wins and the other stands down. Operational mail is not a nudge and ignores
 * all of this.
 */

beforeEach(fn () => $this->freezeTime());

/**
 * Pretend a nudge already went out this many days ago.
 */
function nudgeSentDaysAgo(User $user, DripEmailType $type, int $days): void
{
    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => $type,
        'email_identifier' => 'seed-'.$type->value,
        'sent_at' => now()->subDays($days),
    ]);
}

it('holds a nudge back when another one landed in the last few days', function (): void {
    Mail::fake();
    $user = User::factory()->onboarded()->create();
    nudgeSentDaysAgo($user, DripEmailType::InactiveNoBank, 2);

    (new SendMonthlySummaryReminderEmailJob($user, now()->subMonth()->format('Y-m'), now()->addDays(7)->toDateString()))->handle();

    Mail::assertNothingQueued();
});

it('lets it through once the cooldown has passed', function (): void {
    Mail::fake();
    $user = User::factory()->onboarded()->create();
    nudgeSentDaysAgo($user, DripEmailType::InactiveNoBank, 6);

    (new SendMonthlySummaryReminderEmailJob($user, now()->subMonth()->format('Y-m'), now()->addDays(7)->toDateString()))->handle();

    Mail::assertQueued(MonthlySummaryReminderEmail::class);
});

it('works the other way round too', function (): void {
    Mail::fake();
    $user = User::factory()->onboarded()->create();
    nudgeSentDaysAgo($user, DripEmailType::MonthlySummaryReminder, 1);

    (new SendInactiveNoBankEmailJob($user, now()->toDateString()))->handle();

    Mail::assertNothingQueued();
});

it('does not silence operational mail', function (): void {
    Mail::fake();
    $user = User::factory()->onboarded()->create();
    nudgeSentDaysAgo($user, DripEmailType::MonthlySummaryReminder, 1);

    // The welcome email is not a nudge: it is the one that has to arrive.
    (new SendWelcomeEmailJob($user))->handle();

    Mail::assertQueued(WelcomeEmail::class);
});

it('counts every nudge, not just the ones that clash today', function (): void {
    expect(DripEmailType::nudges())
        ->toContain(DripEmailType::InactiveNoBank)
        ->toContain(DripEmailType::ImportHelp)
        ->toContain(DripEmailType::MonthlySummaryReminder)
        // The report itself is deliberately absent: a reader who is owed a
        // report must not lose it to a reminder about something else.
        ->not->toContain(DripEmailType::MonthlySummary)
        ->not->toContain(DripEmailType::Welcome)
        ->not->toContain(DripEmailType::BankTransactionsSynced);
});

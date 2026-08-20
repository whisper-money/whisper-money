<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendInactiveNoBankEmailJob;
use App\Mail\Drip\InactiveNoBankEmail;
use App\Models\BankingConnection;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

// Freeze time so the day-relative timestamps below cannot straddle midnight and
// fall outside the command's exact-day match.
beforeEach(fn () => $this->freezeTime());

/**
 * Creates an onboarded user whose last activity was $inactiveDaysAgo days ago.
 */
function dormantUser(int $inactiveDaysAgo = 7): User
{
    return User::factory()->onboarded()->create([
        'last_active_at' => now()->subDays($inactiveDaysAgo),
    ]);
}

it('queues the email for an onboarded user with no bank who went quiet a week ago', function () {
    Bus::fake();

    $user = dormantUser();

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertDispatched(
        SendInactiveNoBankEmailJob::class,
        fn (SendInactiveNoBankEmailJob $job): bool => $job->user->is($user)
            && $job->dormantOn === $user->last_active_at->toDateString(),
    );
});

it('skips users who have a bank connected', function () {
    Bus::fake();

    BankingConnection::factory()->for(dormantUser())->create();

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertNotDispatched(SendInactiveNoBankEmailJob::class);
});

it('skips users who were active within the last week', function () {
    Bus::fake();

    dormantUser(inactiveDaysAgo: 2);
    dormantUser(inactiveDaysAgo: 6);

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertNotDispatched(SendInactiveNoBankEmailJob::class);
});

it('skips users who never finished onboarding', function () {
    Bus::fake();

    User::factory()->notOnboarded()->create(['last_active_at' => now()->subDays(7)]);

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertNotDispatched(SendInactiveNoBankEmailJob::class);
});

it('skips users whose activity was never recorded', function () {
    Bus::fake();

    User::factory()->onboarded()->create(['last_active_at' => null]);

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertNotDispatched(SendInactiveNoBankEmailJob::class);
});

it('does nothing when drip emails are disabled', function () {
    config(['mail.drip_emails_enabled' => false]);
    Bus::fake();

    dormantUser();

    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Bus::assertNotDispatched(SendInactiveNoBankEmailJob::class);
});

it('does not queue again on a same-day re-run', function () {
    Mail::fake();

    $user = dormantUser();

    $this->artisan('email:inactive-no-bank')->assertSuccessful();
    $this->artisan('email:inactive-no-bank')->assertSuccessful();

    Mail::assertQueuedCount(1);
    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(1);
});

it('sends the email and records a mail log keyed on the dormancy date', function () {
    Mail::fake();

    $user = dormantUser();
    $dormantOn = $user->last_active_at->toDateString();

    (new SendInactiveNoBankEmailJob($user, $dormantOn))->handle();

    Mail::assertQueued(InactiveNoBankEmail::class);
    expect(UserMailLog::where('user_id', $user->id)->value('email_identifier'))->toBe($dormantOn);
    expect(UserMailLog::where('user_id', $user->id)->value('email_type'))
        ->toBe(DripEmailType::InactiveNoBank);
});

it('sends again for a later dormancy episode', function () {
    Mail::fake();

    $user = dormantUser();
    (new SendInactiveNoBankEmailJob($user, $user->last_active_at->toDateString()))->handle();

    // The user came back and went quiet again: a new episode, so a new email.
    $user->last_active_at = now()->subDays(3);
    $user->save();

    (new SendInactiveNoBankEmailJob($user, $user->last_active_at->toDateString()))->handle();

    Mail::assertQueuedCount(2);
    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(2);
});

it('does not send once the user has connected a bank', function () {
    Mail::fake();

    $user = dormantUser();
    BankingConnection::factory()->for($user)->create();

    (new SendInactiveNoBankEmailJob($user, $user->last_active_at->toDateString()))->handle();

    Mail::assertNothingQueued();
});

it('does not send once the user has come back', function () {
    Mail::fake();

    $user = dormantUser();
    $dormantOn = $user->last_active_at->toDateString();

    // The user opened the app between the command queueing this and it running.
    $user->last_active_at = now();
    $user->save();

    (new SendInactiveNoBankEmailJob($user, $dormantOn))->handle();

    Mail::assertNothingQueued();
    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(0);
});

it('renders the email in the user locale', function () {
    app()->setLocale('es');

    try {
        $html = (new InactiveNoBankEmail(User::factory()->make(['name' => 'Ada'])))->render();
    } finally {
        app()->setLocale('en');
    }

    expect($html)->toContain('Tus números llevan una semana sin actualizarse, Ada');
    expect($html)->toContain('Ir al Panel');
});

it('points its button at the dashboard and offers connecting a bank as a link', function () {
    $html = (new InactiveNoBankEmail(User::factory()->make()))->render();

    expect($html)->toContain(route('dashboard').'?')
        ->toContain(route('settings.connections.index').'?');
});

it('tags both links so the clicks are attributable in PostHog', function () {
    $html = (new InactiveNoBankEmail(User::factory()->make()))->render();

    expect($html)->toContain('utm_source=drip')
        ->toContain('utm_medium=email')
        ->toContain('utm_campaign=inactive-no-bank')
        ->toContain('utm_content=dashboard')
        ->toContain('utm_content=connect-bank');
});

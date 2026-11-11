<?php

use App\Enums\DripEmailType;
use App\Listeners\SendTrialEndingEmail;
use App\Mail\Drip\TrialEndingCanceledEmail;
use App\Mail\Drip\TrialEndingEmail;
use App\Models\Account;
use App\Models\Category;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;
use Laravel\Cashier\Subscription;

beforeEach(function () {
    Mail::fake();

    config(['mail.drip_emails_enabled' => true]);
});

function trialWillEndPayload(array $overrides = [], string $eventId = 'evt_trial_1'): array
{
    return [
        'id' => $eventId,
        'type' => 'customer.subscription.trial_will_end',
        'data' => ['object' => array_merge([
            'id' => 'sub_123',
            'customer' => 'cus_123',
            'status' => 'trialing',
            'trial_end' => now()->addDays(3)->getTimestamp(),
            'cancel_at_period_end' => false,
            'items' => ['data' => [['price' => [
                'unit_amount' => 399,
                'currency' => 'eur',
                'recurring' => ['interval' => 'month', 'interval_count' => 1],
            ]]]],
        ], $overrides)],
    ];
}

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * The local row Cashier keeps for a trial the user set to cancel, which the
 * job reads to check the cancellation still stands when it runs.
 */
function canceledTrialSubscription(User $user): Subscription
{
    return $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_123',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_trial_test',
        'trial_ends_at' => now()->addDays(3),
        'ends_at' => now()->addDays(3),
    ]);
}

function handleTrialWillEnd(array $payload): void
{
    (new SendTrialEndingEmail)->handle(new WebhookReceived($payload));
}

test('a trial_will_end webhook queues the warning email', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);

    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertQueued(TrialEndingEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::TrialEnding->value,
        'email_identifier' => now()->addDays(3)->toDateString(),
    ]);
});

test('a redelivery of the same Stripe event queues nothing more', function () {
    User::factory()->create(['stripe_id' => 'cus_123']);

    handleTrialWillEnd(trialWillEndPayload());
    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertQueuedCount(1);
});

test('a second delivery under a new event id is still deduped by the mail log', function () {
    User::factory()->create(['stripe_id' => 'cus_123']);

    handleTrialWillEnd(trialWillEndPayload(eventId: 'evt_trial_1'));
    handleTrialWillEnd(trialWillEndPayload(eventId: 'evt_trial_2'));

    Mail::assertQueuedCount(1);
});

test('a later trial for the same user is warned about again', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    UserMailLog::factory()->for($user)->create([
        'email_type' => DripEmailType::TrialEnding,
        'email_identifier' => now()->subMonths(6)->toDateString(),
    ]);

    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertQueued(TrialEndingEmail::class);
});

test('nothing is sent while drip emails are switched off', function () {
    User::factory()->create(['stripe_id' => 'cus_123']);
    config(['mail.drip_emails_enabled' => false]);

    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertNothingQueued();
});

test('nothing is sent to a user who cannot receive emails', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    $user->delete();

    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertNothingQueued();
});

test('a trial already set to cancel gets the keep-your-plan email instead', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    canceledTrialSubscription($user);

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true]));

    Mail::assertNotQueued(TrialEndingEmail::class);
    Mail::assertQueued(TrialEndingCanceledEmail::class, fn ($mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::TrialEndingCanceled->value,
        'email_identifier' => now()->addDays(3)->toDateString(),
    ]);
});

test('a trial set to cancel needs no amount in the payload', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    canceledTrialSubscription($user);

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true, 'items' => ['data' => []]]));

    Mail::assertQueued(TrialEndingCanceledEmail::class);
});

test('a redelivered webhook for a trial set to cancel sends one email', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    canceledTrialSubscription($user);

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true], eventId: 'evt_trial_1'));
    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true], eventId: 'evt_trial_1'));
    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true], eventId: 'evt_trial_2'));

    Mail::assertQueuedCount(1);
});

test('a trial resumed before the email goes out is not asked to keep its plan', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    canceledTrialSubscription($user)->update(['ends_at' => null]);

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true]));

    Mail::assertNothingQueued();
});

test('the keep-your-plan email states the end date, what was set up and links to the billing page', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123', 'timezone' => 'Europe/Madrid']);
    canceledTrialSubscription($user);
    Account::factory()->count(2)->for($user)->create();

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true]));

    $mail = Mail::queued(TrialEndingCanceledEmail::class)->first();
    $body = $mail->render();

    expect($body)
        ->toContain($mail->trialEndsAt->setTimezone($user->timezone)->isoFormat('LL'))
        ->toContain('2 accounts')
        ->toContain('/settings/billing?')
        ->toContain('utm_campaign=trial-ending-canceled')
        ->not->toContain('/settings/billing/portal')
        ->not->toContain('€');
});

test('the keep-your-plan email leaves onboarding estimates out of what was set up', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    canceledTrialSubscription($user);
    Transaction::factory()->count(2)->for($user)->create();
    giveOnboardingEstimates($user, 4);

    handleTrialWillEnd(trialWillEndPayload(['cancel_at_period_end' => true]));

    expect(Mail::queued(TrialEndingCanceledEmail::class)->first()->render())
        ->toContain('2 transactions')
        ->not->toContain('6 transactions');
});

test('nothing is sent for an unknown Stripe customer', function () {
    User::factory()->create(['stripe_id' => 'cus_other']);

    handleTrialWillEnd(trialWillEndPayload());

    Mail::assertNothingQueued();
});

test('other Stripe events are ignored', function () {
    User::factory()->create(['stripe_id' => 'cus_123']);

    handleTrialWillEnd([...trialWillEndPayload(), 'type' => 'customer.subscription.updated']);

    Mail::assertNothingQueued();
});

test('the email states the amount from the payload, not a hardcoded price', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123', 'timezone' => 'Europe/Madrid']);

    handleTrialWillEnd(trialWillEndPayload(['items' => ['data' => [['price' => [
        'unit_amount' => 899,
        'currency' => 'eur',
    ]]]]]));

    Mail::assertQueued(TrialEndingEmail::class, function (TrialEndingEmail $mail) use ($user) {
        $body = $mail->render();

        return str_contains($body, '€8.99')
            && str_contains($body, $mail->trialEndsAt->setTimezone($user->timezone)->isoFormat('LL'))
            && str_contains($body, '/settings/billing/portal');
    });
});

test('the email states the trial end date in the reader timezone', function () {
    // Pinned to a moment where UTC and the reader's day disagree: the trial ends
    // late on the 28th in UTC, which is already the 29th in Madrid. Without the
    // pin this only fails for the two hours a day the two calendars differ, and
    // the bug it guards — a public Mailable property silently overwriting the
    // localised value passed to Content::with — went unnoticed that way.
    Carbon::setTestNow('2026-08-25 22:30:00');

    $user = User::factory()->create(['stripe_id' => 'cus_123', 'timezone' => 'Europe/Madrid']);

    // Its own event id, so this never rides on the shared one being unclaimed.
    handleTrialWillEnd(trialWillEndPayload(eventId: 'evt_trial_timezone'));

    $mail = Mail::queued(TrialEndingEmail::class)->first();

    expect($mail)->not->toBeNull()
        ->and($user->timezone)->toBe('Europe/Madrid')
        ->and($mail->trialEndsAt->toDateString())->toBe('2026-08-28')
        ->and($mail->render())->toContain('August 29, 2026');
});

test('a failed dispatch hands the event back so Stripe can redeliver it', function () {
    User::factory()->create(['stripe_id' => 'cus_123']);

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));

    expect(fn () => handleTrialWillEnd(trialWillEndPayload()))
        ->toThrow(RuntimeException::class);

    expect(Cache::has('trial-ending:stripe-event:evt_trial_1'))->toBeFalse();
});

test('the email is titled by the trial end date and says what keeping or canceling means', function () {
    $user = User::factory()->create(['stripe_id' => 'cus_123', 'timezone' => 'Europe/Madrid']);
    $mail = new TrialEndingEmail($user, now()->addDays(3)->toImmutable(), 899, 'eur');
    $date = $mail->trialEndsAt->setTimezone('Europe/Madrid')->isoFormat('LL');

    $mail->assertHasSubject("Your free trial ends on {$date}");
    expect($mail->render())
        ->toContain('If you keep it')
        ->toContain('You are not charged. Everything you imported stays yours; syncing and the AI stop.')
        ->toContain('Open Whisper Money')
        ->toContain('Change or cancel your plan')
        ->toContain('/settings/billing/portal')
        // Nothing synced yet: no stats block rather than a row of zeros.
        ->not->toContain('In your trial so far');
});

test('the email shows what the bank synced during the trial, leaving out a 0%', function (bool $categorized, bool $showsShare) {
    $user = User::factory()->create(['stripe_id' => 'cus_123']);
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_123',
        'stripe_status' => 'trialing',
        'stripe_price' => 'price_trial_test',
        'trial_ends_at' => now()->addDays(3),
    ])->forceFill(['created_at' => now()->subWeek()])->save();
    $category = $categorized ? Category::factory()->for($user)->create() : null;
    Transaction::factory()->count(4)->enableBanking()
        ->for($user)->for(Account::factory()->for($user))
        ->create(['category_id' => $category?->id]);

    $body = (new TrialEndingEmail($user, now()->addDays(3)->toImmutable(), 899, 'eur'))->render();

    expect($body)->toContain('In your trial so far')->toContain('4 movements synced from your bank');
    expect(str_contains($body, '100% of them sorted into a category'))->toBe($showsShare);
})->with([
    'all sorted' => [true, true],
    'none sorted' => [false, false],
]);

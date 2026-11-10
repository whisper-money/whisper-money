<?php

use App\Enums\BankingConnectionStatus;
use App\Enums\DripEmailType;
use App\Listeners\SendAnnualRenewalReminder;
use App\Mail\Drip\AnnualRenewalEmail;
use App\Models\Account;
use App\Models\AutomationRule;
use App\Models\BankingConnection;
use App\Models\Budget;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;

const RENEWAL_DAY = 365 * 86400;

beforeEach(function () {
    Mail::fake();

    config(['mail.drip_emails_enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * A user with a live subscription `sub_123` on Stripe customer `cus_123`.
 *
 * @param  array<string, mixed>  $subscription
 */
function annualSubscriber(array $subscription = []): User
{
    $user = User::factory()->create(['stripe_id' => 'cus_123', 'timezone' => 'Europe/Madrid']);

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_yearly',
        ...$subscription,
    ]);

    return $user;
}

/**
 * An `invoice.upcoming` payload as the dahlia API version sends it: the
 * subscription under `parent` and the line carrying only its period.
 *
 * @param  array<string, mixed>  $overrides
 */
function upcomingInvoicePayload(array $overrides = [], string $eventId = 'evt_upcoming_1', int $periodLength = RENEWAL_DAY): array
{
    $start = now()->addDays(14)->getTimestamp();

    return [
        'id' => $eventId,
        'type' => 'invoice.upcoming',
        'data' => ['object' => array_merge([
            'object' => 'invoice',
            'customer' => 'cus_123',
            'amount_due' => 5394,
            'currency' => 'eur',
            'next_payment_attempt' => $start + 3600,
            'parent' => ['subscription_details' => ['subscription' => 'sub_123']],
            'lines' => ['data' => [[
                'amount' => 5394,
                'period' => ['start' => $start, 'end' => $start + $periodLength],
            ]]],
        ], $overrides)],
    ];
}

function handleUpcomingInvoice(array $payload): void
{
    (new SendAnnualRenewalReminder)->handle(new WebhookReceived($payload));
}

test('an upcoming yearly renewal queues the reminder and logs it against the renewal date', function () {
    $user = annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertQueued(AnnualRenewalEmail::class, fn (AnnualRenewalEmail $mail) => $mail->hasTo($user->email));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::AnnualRenewal->value,
        'email_identifier' => now()->addDays(14)->toDateString(),
    ]);
});

test('a yearly price on the line is recognised on older API versions', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload([
        'subscription' => 'sub_123',
        'parent' => null,
        'lines' => ['data' => [[
            'period' => ['start' => now()->addDays(14)->getTimestamp(), 'end' => now()->addDays(14)->getTimestamp()],
            'price' => ['recurring' => ['interval' => 'year', 'interval_count' => 1]],
        ]]],
    ]));

    Mail::assertQueued(AnnualRenewalEmail::class);
});

test('the renewal date comes from the yearly line, not a proration line listed first', function () {
    annualSubscriber();

    $payload = upcomingInvoicePayload();
    array_unshift($payload['data']['object']['lines']['data'], [
        'amount' => 150,
        'period' => ['start' => now()->getTimestamp(), 'end' => now()->getTimestamp()],
    ]);

    handleUpcomingInvoice($payload);

    Mail::assertQueued(AnnualRenewalEmail::class, fn (AnnualRenewalEmail $mail) => $mail->renewsAt->toDateString() === now()->addDays(14)->toDateString());
});

test('a past-due subscription sends nothing', function () {
    annualSubscriber(['stripe_status' => 'past_due']);

    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertNothingQueued();
});

test('a monthly renewal sends nothing', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(periodLength: 30 * 86400));

    Mail::assertNothingQueued();
});

test('a monthly price on the line sends nothing even with a long period', function () {
    annualSubscriber();

    $payload = upcomingInvoicePayload();
    $payload['data']['object']['lines']['data'][0]['price'] = ['recurring' => ['interval' => 'month']];

    handleUpcomingInvoice($payload);

    Mail::assertNothingQueued();
});

test('a subscription set to cancel at period end sends nothing', function () {
    annualSubscriber(['ends_at' => now()->addDays(14)]);

    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertNothingQueued();
});

test('a subscription still in its trial is left to the trial-ending email', function () {
    annualSubscriber(['stripe_status' => 'trialing', 'trial_ends_at' => now()->addDays(14)]);

    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertNothingQueued();
});

test('a renewal with nothing to charge sends nothing', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(['amount_due' => 0]));

    Mail::assertNothingQueued();
});

test('an unknown Stripe customer sends nothing', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(['customer' => 'cus_other']));

    Mail::assertNothingQueued();
});

test('a subscription that is not the user\'s sends nothing', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(['parent' => ['subscription_details' => ['subscription' => 'sub_other']]]));

    Mail::assertNothingQueued();
});

test('nothing is sent while drip emails are switched off', function () {
    annualSubscriber();
    config(['mail.drip_emails_enabled' => false]);

    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertNothingQueued();
});

test('other Stripe events are ignored', function () {
    annualSubscriber();

    handleUpcomingInvoice([...upcomingInvoicePayload(), 'type' => 'invoice.created']);

    Mail::assertNothingQueued();
});

test('a redelivery of the same event queues nothing more', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload());
    handleUpcomingInvoice(upcomingInvoicePayload());

    Mail::assertQueuedCount(1);
});

test('a second event for the same renewal is deduped by the mail log', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(eventId: 'evt_upcoming_1'));
    handleUpcomingInvoice(upcomingInvoicePayload(eventId: 'evt_upcoming_2'));

    Mail::assertQueuedCount(1);
});

test('the next year\'s renewal gets its own reminder', function () {
    annualSubscriber();

    handleUpcomingInvoice(upcomingInvoicePayload(eventId: 'evt_upcoming_1'));

    Carbon::setTestNow(now()->addYear());
    handleUpcomingInvoice(upcomingInvoicePayload(eventId: 'evt_upcoming_2'));

    Mail::assertQueuedCount(2);
});

test('a failed dispatch hands the event back so Stripe can redeliver it', function () {
    annualSubscriber();

    $this->mock(Dispatcher::class)
        ->shouldReceive('dispatch')
        ->once()
        ->andThrow(new RuntimeException('queue unavailable'));

    expect(fn () => handleUpcomingInvoice(upcomingInvoicePayload()))
        ->toThrow(RuntimeException::class);

    expect(Cache::has('annual-renewal:stripe-event:evt_upcoming_1'))->toBeFalse();
});

test('the email states the date and amount and recaps the year without money amounts', function () {
    Carbon::setTestNow('2027-05-01 22:30:00');

    $user = annualSubscriber();
    $account = Account::factory()->for($user)->create();
    Transaction::factory()->count(3)->for($user)->for($account)->create(['amount' => 123456]);
    AutomationRule::factory()->for($user)->create();

    // Renews late on the 15th in UTC, which is already the 16th in Madrid.
    handleUpcomingInvoice(upcomingInvoicePayload(['currency' => 'eur', 'amount_due' => 5394]));

    $mail = Mail::queued(AnnualRenewalEmail::class)->first();
    $body = $mail->render();

    expect($mail->envelope()->subject)->toBe('Your Whisper Money annual plan renews on May 16, 2027')
        ->and($body)->toContain('May 16, 2027')
        ->and($body)->toContain('€53.94')
        ->and($body)->toContain('1 account tracked')
        ->and($body)->toContain('3 transactions added this year')
        ->and($body)->toContain('1 automation rule doing the sorting for you')
        ->and($body)->not->toContain('savings goal')
        ->and($body)->not->toContain('1,234.56')
        ->and($body)->toContain('/settings/billing?');
});

test('the recap leaves out archived records and broken bank connections', function () {
    $user = annualSubscriber();
    Account::factory()->for($user)->create(['archived_at' => now()]);
    Budget::factory()->for($user)->create(['archived_at' => now()]);
    BankingConnection::factory()->for($user)->create(['status' => BankingConnectionStatus::Expired]);

    handleUpcomingInvoice(upcomingInvoicePayload());

    expect(Mail::queued(AnnualRenewalEmail::class)->first()->render())
        ->not->toContain('account tracked')
        ->not->toContain('budget')
        ->not->toContain('syncing on its own')
        ->not->toContain('Your year with Whisper Money');
});

test('the email is translated for a Spanish reader', function () {
    $user = annualSubscriber();
    $user->forceFill(['locale' => 'es', 'format_locale' => 'es-ES'])->save();
    Account::factory()->count(2)->for($user)->create();

    handleUpcomingInvoice(upcomingInvoicePayload());

    $mail = Mail::queued(AnnualRenewalEmail::class)->first();
    $mail->locale('es');

    expect($mail->render())
        ->toContain('Tu año con Whisper Money')
        ->toContain('2 cuentas controladas')
        ->toContain('53,94');
});

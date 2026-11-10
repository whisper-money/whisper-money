<?php

use App\Enums\DripEmailType;
use App\Enums\DunningStep;
use App\Mail\Drip\PaymentActionRequiredEmail;
use App\Mail\Drip\PaymentFailedEmail;
use App\Models\FailedInvoice;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();

    config(['mail.drip_emails_enabled' => true]);
});

afterEach(function () {
    Carbon::setTestNow();
});

/**
 * An open failed invoice first declined so many days ago, on a subscription
 * in the given state.
 *
 * @param  array<string, mixed>  $subscription
 * @param  array<string, mixed>  $invoice
 */
function failedInvoiceDaysAgo(int $days, array $subscription = [], array $invoice = []): FailedInvoice
{
    $user = User::factory()->create();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_'.$user->id,
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_monthly',
        ...$subscription,
    ]);

    return FailedInvoice::factory()->create([
        'user_id' => $user->id,
        'stripe_subscription_id' => 'sub_'.$user->id,
        'first_failed_at' => now()->subDays($days)->subHour(),
        ...$invoice,
    ]);
}

test('the right reminder goes out on the right day', function (int $days, ?DunningStep $step) {
    $invoice = failedInvoiceDaysAgo($days);

    $this->artisan('email:payment-failed-reminders')->assertSuccessful();

    if ($step === null) {
        Mail::assertNothingQueued();

        return;
    }

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => $mail->step === $step
        && $mail->hasTo($invoice->user->email));
    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $invoice->user_id,
        'email_type' => DripEmailType::PaymentFailed->value,
        'email_identifier' => $invoice->stripe_invoice_id.':'.$step->value,
    ]);
})->with([
    'day 1' => [1, null],
    'day 3' => [3, DunningStep::Reminder],
    'day 5' => [5, DunningStep::Reminder],
    'day 7' => [7, DunningStep::SecondReminder],
    'day 14' => [14, DunningStep::FinalNotice],
    'day 20' => [20, DunningStep::FinalNotice],
    'day 31' => [31, null],
]);

test('each step goes out once per invoice however often the command runs', function () {
    failedInvoiceDaysAgo(3);

    $this->artisan('email:payment-failed-reminders');
    $this->artisan('email:payment-failed-reminders');

    Mail::assertQueuedCount(1);
});

test('the sequence walks through every step over the retry window', function () {
    $invoice = failedInvoiceDaysAgo(0);

    foreach ([3, 7, 14] as $day) {
        Carbon::setTestNow($invoice->first_failed_at->copy()->addDays($day)->addHour());
        $this->artisan('email:payment-failed-reminders');
    }

    Mail::assertQueuedCount(3);
    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => $mail->step === DunningStep::FinalNotice
        && $mail->envelope()->subject === 'Last notice: update your card to keep Whisper Money');
});

test('a missed run sends only the latest step due', function () {
    failedInvoiceDaysAgo(8);

    $this->artisan('email:payment-failed-reminders');

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => $mail->step === DunningStep::SecondReminder);
});

test('nothing goes out once the invoice is paid or voided', function () {
    failedInvoiceDaysAgo(3, invoice: ['resolved_at' => now()]);

    $this->artisan('email:payment-failed-reminders');

    Mail::assertNothingQueued();
});

test('nothing goes out once the subscription is no longer retrying', function (array $subscription) {
    failedInvoiceDaysAgo(3, $subscription);

    $this->artisan('email:payment-failed-reminders');

    Mail::assertNothingQueued();
})->with([
    'paid again' => [['stripe_status' => 'active']],
    'ended' => [['stripe_status' => 'canceled', 'ends_at' => now()->subDay()]],
    'set to cancel' => [['ends_at' => now()->addWeek()]],
    'unpaid, retries over' => [['stripe_status' => 'unpaid']],
]);

test('a payment waiting on the bank is reminded to confirm, not to change card', function () {
    failedInvoiceDaysAgo(7, invoice: ['action_required_at' => now()->subDays(7), 'payment_intent_id' => 'pi_3ds']);

    $this->artisan('email:payment-failed-reminders');

    Mail::assertNotQueued(PaymentFailedEmail::class);
    Mail::assertQueued(PaymentActionRequiredEmail::class, fn (PaymentActionRequiredEmail $mail) => $mail->step === DunningStep::SecondReminder
        && str_contains($mail->render(), 'utm_content%3Dday_7'));
});

test('nothing is sent while drip emails are switched off', function () {
    failedInvoiceDaysAgo(3);
    config(['mail.drip_emails_enabled' => false]);

    $this->artisan('email:payment-failed-reminders')->assertSuccessful();

    Mail::assertNothingQueued();
});

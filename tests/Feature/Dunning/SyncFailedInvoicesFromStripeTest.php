<?php

use App\Enums\DripEmailType;
use App\Listeners\SyncFailedInvoicesFromStripe;
use App\Mail\Drip\PaymentActionRequiredEmail;
use App\Mail\Drip\PaymentFailedEmail;
use App\Models\FailedInvoice;
use App\Models\User;
use App\Models\UserSetting;
use App\Services\Stripe\InvoicePaymentIntentResolver;
use Illuminate\Support\Facades\Mail;
use Laravel\Cashier\Events\WebhookReceived;

beforeEach(function () {
    Mail::fake();

    config(['mail.drip_emails_enabled' => true]);
});

/**
 * A user on Stripe customer `cus_dunning` with subscription `sub_dunning`.
 *
 * @param  array<string, mixed>  $subscription
 */
function dunningSubscriber(array $subscription = []): User
{
    $user = User::factory()->create(['stripe_id' => 'cus_dunning', 'locale' => 'en']);

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_dunning',
        'stripe_status' => 'past_due',
        'stripe_price' => 'price_monthly',
        ...$subscription,
    ]);

    return $user;
}

/**
 * An invoice event as the dahlia API version sends it: the subscription under
 * `parent`, the line carrying only its period.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function stripeInvoiceEvent(string $type, array $overrides = []): array
{
    $periodStart = now()->getTimestamp();

    return [
        'id' => 'evt_'.fake()->unique()->bothify('########'),
        'type' => $type,
        'data' => ['object' => array_merge([
            'object' => 'invoice',
            'id' => 'in_dunning',
            'customer' => 'cus_dunning',
            'amount_due' => 599,
            'currency' => 'eur',
            'billing_reason' => 'subscription_cycle',
            'attempt_count' => 1,
            'next_payment_attempt' => now()->addDays(2)->getTimestamp(),
            'parent' => ['subscription_details' => ['subscription' => 'sub_dunning']],
            'lines' => ['data' => [[
                'amount' => 599,
                'period' => ['start' => $periodStart, 'end' => $periodStart + 30 * 86400],
            ]]],
        ], $overrides)],
    ];
}

function handleStripeInvoiceEvent(array $payload): void
{
    app(SyncFailedInvoicesFromStripe::class)->handle(new WebhookReceived($payload));
}

test('the first failure records the invoice and sends the payment failed email once', function () {
    $user = dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => $mail->hasTo($user->email)
        && $mail->invoice->stripe_invoice_id === 'in_dunning'
        && ! $mail->invoice->is_first_charge_after_trial);

    expect(FailedInvoice::query()->sole())
        ->user_id->toBe($user->id)
        ->amount_due->toBe(599)
        ->billing_interval->toBe('month')
        ->resolved_at->toBeNull();

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => DripEmailType::PaymentFailed->value,
        'email_identifier' => 'in_dunning:first',
    ]);
});

test('a later retry failing updates the invoice without another email', function () {
    dunningSubscriber();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    $nextAttempt = now()->addDays(5)->startOfSecond();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', [
        'attempt_count' => 2,
        'next_payment_attempt' => $nextAttempt->getTimestamp(),
    ]));

    Mail::assertQueuedCount(1);
    expect(FailedInvoice::query()->sole())
        ->attempt_count->toBe(2)
        ->next_payment_attempt_at->toDateTimeString()->toBe($nextAttempt->toDateTimeString());
});

test('the first charge after the trial is worded as such', function () {
    $trialEnd = now()->startOfSecond();
    $user = dunningSubscriber(['trial_ends_at' => $trialEnd]);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertQueued(PaymentFailedEmail::class, function (PaymentFailedEmail $mail) {
        $html = $mail->render();

        return $mail->invoice->is_first_charge_after_trial
            && $mail->envelope()->subject === "Your first Whisper Money payment didn't go through"
            && str_contains($html, 'Your free trial has ended');
    });
});

test('a subscription created without a trial is not told its trial ended', function () {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['billing_reason' => 'subscription_create']));

    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => ! $mail->invoice->is_first_charge_after_trial
        && ! str_contains($mail->render(), 'free trial'));
});

test('a failure arriving after the payment went through sends nothing', function () {
    dunningSubscriber(['stripe_status' => 'active']);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertNothingQueued();
});

test('a payload of an invoice no longer open opens nothing', function () {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['status' => 'paid']));

    expect(FailedInvoice::query()->count())->toBe(0);
});

test('an invoice first seen on a later retry keeps Stripe\'s calendar and gets the first email', function () {
    dunningSubscriber();
    $finalizedAt = now()->subDays(4)->startOfSecond();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', [
        'attempt_count' => 3,
        'status_transitions' => ['finalized_at' => $finalizedAt->getTimestamp()],
    ]));

    expect(FailedInvoice::query()->sole()->first_failed_at->toDateTimeString())->toBe($finalizedAt->toDateTimeString());
    Mail::assertQueued(PaymentFailedEmail::class);
});

test('a late redelivery of an earlier retry does not wind the attempt count back', function () {
    dunningSubscriber();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['attempt_count' => 3]));

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['attempt_count' => 2]));

    expect(FailedInvoice::query()->sole()->attempt_count)->toBe(3);
});

test('the email quotes the amount and plan and links to updating the card', function () {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertQueued(PaymentFailedEmail::class, function (PaymentFailedEmail $mail) {
        $html = $mail->render();

        return str_contains($html, '€5.99')
            && str_contains($html, 'Whisper Money monthly plan')
            && str_contains($html, 'flow=payment_method_update')
            && str_contains($html, 'utm_campaign=dunning')
            && str_contains($html, 'utm_content=first');
    });
});

test('the email is written in the reader\'s language', function () {
    $user = dunningSubscriber();
    $user->forceFill(['locale' => 'es'])->save();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertQueued(PaymentFailedEmail::class, fn (PaymentFailedEmail $mail) => $mail->locale === 'es');
});

test('invoices that are not dunned are skipped', function (array $overrides) {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', $overrides));

    Mail::assertNothingQueued();
    expect(FailedInvoice::query()->count())->toBe(0);
})->with([
    'nothing to charge' => [['amount_due' => 0]],
    'a one-off invoice' => [['billing_reason' => 'manual', 'parent' => null]],
    'a plan change proration' => [['billing_reason' => 'subscription_update']],
    'an unknown customer' => [['customer' => 'cus_other']],
    'a subscription we do not have' => [['parent' => ['subscription_details' => ['subscription' => 'sub_other']]]],
]);

test('a user without a local subscription is skipped', function () {
    User::factory()->create(['stripe_id' => 'cus_dunning']);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertNothingQueued();
});

test('an incomplete subscription is left to checkout, which shows the decline', function () {
    dunningSubscriber(['stripe_status' => 'incomplete']);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['billing_reason' => 'subscription_create']));

    Mail::assertNothingQueued();
});

test('the state is kept but nothing is sent while drip emails are off', function () {
    dunningSubscriber();
    config(['mail.drip_emails_enabled' => false]);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertNothingQueued();
    expect(FailedInvoice::query()->count())->toBe(1);
});

test('the marketing opt-out does not silence it', function () {
    $user = dunningSubscriber();
    UserSetting::factory()->for($user)->create(['notify_marketing' => false]);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    Mail::assertQueued(PaymentFailedEmail::class);
});

test('an invoice paid, voided or written off closes the row', function (string $type) {
    dunningSubscriber();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    handleStripeInvoiceEvent(stripeInvoiceEvent($type));

    expect(FailedInvoice::query()->sole()->resolved_at)->not->toBeNull();
})->with(['invoice.paid', 'invoice.payment_succeeded', 'invoice.voided', 'invoice.marked_uncollectible']);

test('a deleted subscription closes its rows', function () {
    dunningSubscriber();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));

    handleStripeInvoiceEvent([
        'id' => 'evt_deleted',
        'type' => 'customer.subscription.deleted',
        'data' => ['object' => ['object' => 'subscription', 'id' => 'sub_dunning', 'customer' => 'cus_dunning']],
    ]);

    expect(FailedInvoice::query()->sole()->resolved_at)->not->toBeNull();
});

test('a failure arriving after the invoice was paid does not reopen it', function () {
    dunningSubscriber();
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.paid'));
    Mail::fake();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed', ['attempt_count' => 2]));

    expect(FailedInvoice::query()->sole()->resolved_at)->not->toBeNull();
    Mail::assertNothingQueued();
});

test('a payment needing 3D Secure sends the confirmation email instead of the declined one', function () {
    $user = dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', ['payment_intent' => 'pi_3ds']));
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_failed'));
    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', ['payment_intent' => 'pi_3ds']));

    Mail::assertQueuedCount(1);
    Mail::assertQueued(PaymentActionRequiredEmail::class, function (PaymentActionRequiredEmail $mail) use ($user) {
        $html = $mail->render();
        $confirmUrl = route('cashier.payment', [
            'id' => 'pi_3ds',
            'redirect' => route('settings.billing', ['utm_source' => 'drip', 'utm_medium' => 'email', 'utm_campaign' => 'dunning', 'utm_content' => 'first']),
        ]);

        return $mail->hasTo($user->email) && str_contains($html, e($confirmUrl));
    });

    expect(FailedInvoice::query()->sole())
        ->payment_intent_id->toBe('pi_3ds')
        ->action_required_at->not->toBeNull();

    $this->assertDatabaseHas('user_mail_logs', [
        'email_type' => DripEmailType::PaymentActionRequired->value,
        'email_identifier' => 'in_dunning:first',
    ]);
});

test('the payment intent is read from the invoice payments of newer API versions', function () {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', [
        'payments' => ['data' => [
            ['status' => 'canceled', 'payment' => ['payment_intent' => 'pi_old']],
            ['status' => 'open', 'payment' => ['payment_intent' => 'pi_open']],
        ]],
    ]));

    expect(FailedInvoice::query()->sole()->payment_intent_id)->toBe('pi_open');
});

test('the payment intent is asked from Stripe when the payload does not carry it', function () {
    dunningSubscriber();
    $resolver = Mockery::mock(InvoicePaymentIntentResolver::class);
    $resolver->shouldReceive('for')->once()->andReturn('pi_fetched');
    app()->instance(InvoicePaymentIntentResolver::class, $resolver);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required'));

    expect(FailedInvoice::query()->sole()->payment_intent_id)->toBe('pi_fetched');
    Mail::assertQueued(PaymentActionRequiredEmail::class);
});

test('a first payment held by the bank outside checkout asks for the confirmation', function () {
    dunningSubscriber(['stripe_status' => 'incomplete']);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', [
        'payment_intent' => 'pi_3ds',
        'billing_reason' => 'subscription_create',
    ]));

    Mail::assertQueued(PaymentActionRequiredEmail::class);
});

test('a renewal still carrying the checkout tag is not mistaken for checkout', function () {
    dunningSubscriber();

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', [
        'payment_intent' => 'pi_3ds',
        'parent' => ['subscription_details' => ['subscription' => 'sub_dunning', 'metadata' => ['is_on_session_checkout' => true]]],
    ]));

    Mail::assertQueued(PaymentActionRequiredEmail::class);
});

test('a confirmation asked for during checkout sends nothing', function () {
    dunningSubscriber(['stripe_status' => 'incomplete']);

    handleStripeInvoiceEvent(stripeInvoiceEvent('invoice.payment_action_required', [
        'payment_intent' => 'pi_3ds',
        'billing_reason' => 'subscription_create',
        'parent' => ['subscription_details' => ['subscription' => 'sub_dunning', 'metadata' => ['is_on_session_checkout' => true]]],
    ]));

    Mail::assertNothingQueued();
    expect(FailedInvoice::query()->count())->toBe(0);
});

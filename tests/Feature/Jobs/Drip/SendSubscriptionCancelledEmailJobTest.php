<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Mail\Drip\CancelledBeforePayingEmail;
use App\Mail\Drip\SubscriptionCancelledEmail;
use App\Models\User;
use App\Models\UserMailLog;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Mail;
use Stripe\Collection as StripeCollection;
use Stripe\Service\InvoiceService;
use Stripe\StripeClient;

beforeEach(function () {
    Mail::fake();

    config(['subscriptions.enabled' => true]);
});

/**
 * Bind a Stripe client whose paid-invoice listing answers with invoices for
 * the given amounts (in cents). A trial opens with a paid invoice for 0.
 *
 * @param  list<int>  $amountsPaid
 */
function bindPaidInvoices(array $amountsPaid): void
{
    $invoiceService = Mockery::mock(InvoiceService::class);
    $invoiceService->shouldReceive('all')
        ->with(Mockery::on(fn (array $params) => $params['status'] === 'paid' && $params['customer'] === 'cus_cancelled'))
        ->andReturn(StripeCollection::constructFrom([
            'object' => 'list',
            'data' => array_map(fn (int $amount) => ['object' => 'invoice', 'status' => 'paid', 'amount_paid' => $amount], $amountsPaid),
            'has_more' => false,
        ]));

    $stripeClient = Mockery::mock(StripeClient::class);
    $stripeClient->invoices = $invoiceService;
    app()->bind(StripeClient::class, fn () => $stripeClient);
}

function cancelledCustomer(string $status = 'canceled', array $attributes = []): User
{
    $user = User::factory()->create(['stripe_id' => 'cus_cancelled', ...$attributes]);

    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_cancelled_'.$user->id,
        'stripe_status' => $status,
        'stripe_price' => 'price_monthly',
        'ends_at' => $status === 'canceled' ? now()->subMinute() : null,
    ]);

    return $user->fresh();
}

test('a customer who paid gets the win-back offer', function () {
    bindPaidInvoices([0, 399]);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(SubscriptionCancelledEmail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertNotQueued(CancelledBeforePayingEmail::class);
});

test('a customer whose only paid invoice is the zero-euro trial one gets the no-discount note', function () {
    bindPaidInvoices([0]);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(CancelledBeforePayingEmail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertNotQueued(SubscriptionCancelledEmail::class);
});

test('a customer with no paid invoice at all gets the no-discount note', function () {
    bindPaidInvoices([]);

    (new SendSubscriptionCancelledEmailJob(cancelledCustomer()))->handle();

    Mail::assertQueued(CancelledBeforePayingEmail::class);
});

test('a user Stripe has never seen gets the no-discount note without asking Stripe', function () {
    app()->bind(StripeClient::class, fn () => throw new RuntimeException('Stripe must not be called'));
    $user = User::factory()->create(['stripe_id' => null]);

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(CancelledBeforePayingEmail::class);
});

test('the send is logged once and never repeated, whichever variant went out', function () {
    bindPaidInvoices([399]);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();
    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueuedCount(1);
    expect(UserMailLog::query()->where('user_id', $user->id)->where('email_type', DripEmailType::SubscriptionCancelled)->count())->toBe(1);
});

test('nobody still on a plan is told they left', function (string $status) {
    bindPaidInvoices([399]);

    (new SendSubscriptionCancelledEmailJob(cancelledCustomer($status)))->handle();

    Mail::assertNothingQueued();
})->with(['active', 'trialing', 'past_due']);

test('a plan cancelled but still inside its paid period is not written to yet', function () {
    bindPaidInvoices([399]);
    $user = cancelledCustomer('active');
    $user->subscription('default')->update(['ends_at' => now()->addWeek()]);

    (new SendSubscriptionCancelledEmailJob($user->fresh()))->handle();

    Mail::assertNothingQueued();
});

test('the shared accounts are never written to', function (User $user) {
    bindPaidInvoices([399]);

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertNothingQueued();
})->with([
    'demo account' => fn () => cancelledCustomer(attributes: ['email' => config('app.demo.email')]),
    'seeded demo subscription' => function () {
        $user = User::factory()->create();
        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_demo_'.$user->id,
            'stripe_status' => 'canceled',
            'stripe_price' => 'price_demo_free',
            'ends_at' => now()->subMinute(),
        ]);

        return $user->fresh();
    },
]);

test('a reader who switched off news and offers gets neither variant', function () {
    bindPaidInvoices([399]);
    $user = cancelledCustomer();
    UserSetting::factory()->for($user)->create(['notify_marketing' => false]);

    (new SendSubscriptionCancelledEmailJob($user->fresh()))->handle();

    Mail::assertNothingQueued();
});

test('the win-back email offers COMEBACK30 with an unsubscribe footer', function () {
    $user = User::factory()->create(['name' => 'Marta']);

    $body = (new SubscriptionCancelledEmail($user))->render();

    expect($body)
        ->toContain('COMEBACK30')
        ->toContain('30% off')
        ->not->toContain('CONTINUE50')
        ->toContain('/subscribe')
        ->toContain('Stop receiving news and offers')
        ->toContain('/unsubscribe/marketing');
});

test('the no-discount note asks what was missing and offers no code', function () {
    $user = User::factory()->create(['name' => 'Marta']);
    $mail = new CancelledBeforePayingEmail($user);

    $body = $mail->render();

    expect($mail->envelope()->subject)->toBe('What was missing?')
        ->and($mail->envelope()->replyTo)->not->toBeEmpty()
        ->and($body)
        ->toContain('Hey Marta, what was missing?')
        ->toContain('reply to this email')
        ->not->toContain('COMEBACK30')
        ->not->toContain('% off')
        ->toContain('Stop receiving news and offers');
});

test('both variants are translated to Spanish', function () {
    $user = User::factory()->create(['name' => 'Marta']);
    app()->setLocale('es');

    expect((new CancelledBeforePayingEmail($user))->render())->toContain('Hola Marta, ¿qué te faltó?')
        ->and((new SubscriptionCancelledEmail($user))->render())->toContain('COMEBACK30</strong> para obtener un');
});

test('the cancellation email counts as marketing while the trial-ending warning stays operational', function () {
    expect(DripEmailType::SubscriptionCancelled->isMarketing())->toBeTrue()
        ->and(DripEmailType::TrialEnding->isMarketing())->toBeFalse();
});

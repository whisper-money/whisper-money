<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Mail\Drip\CancelledAfterPayingEmail;
use App\Mail\Drip\CancelledBeforePayingEmail;
use App\Mail\Drip\SubscriptionCancelledEmail;
use App\Models\User;
use App\Models\UserMailLog;
use App\Models\UserSetting;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\ApiConnectionException;
use Stripe\Service\SubscriptionService;
use Stripe\StripeClient;

beforeEach(function () {
    Mail::fake();

    config(['subscriptions.enabled' => true]);
});

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

test('a customer who paid and cancelled it themselves gets the no-discount note, having turned down the portal offer', function () {
    bindStripeBillingHistory([0, 399], cancellationReason: 'cancellation_requested');
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(CancelledAfterPayingEmail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertNotQueued(SubscriptionCancelledEmail::class);
    Mail::assertNotQueued(CancelledBeforePayingEmail::class);
});

test('a customer who paid and lost the plan some other way gets the win-back offer', function (?string $reason) {
    bindStripeBillingHistory([0, 399], cancellationReason: $reason);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(SubscriptionCancelledEmail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertNotQueued(CancelledAfterPayingEmail::class);
    Mail::assertNotQueued(CancelledBeforePayingEmail::class);
})->with([
    'renewal failed' => 'payment_failed',
    'payment disputed' => 'payment_disputed',
    'no reason given' => null,
]);

test('a customer who paid gets the win-back offer when Stripe has no cancelled subscription to read a reason from', function () {
    bindStripeBillingHistory([399], hasCancelledSubscription: false);

    (new SendSubscriptionCancelledEmailJob(cancelledCustomer()))->handle();

    Mail::assertQueued(SubscriptionCancelledEmail::class);
});

test('a Stripe outage while reading the cancellation reason fails the attempt instead of guessing a variant', function () {
    bindStripeBillingHistory([399]);
    $stripe = app(StripeClient::class);
    $stripe->subscriptions = Mockery::mock(SubscriptionService::class);
    $stripe->subscriptions->shouldReceive('all')->andThrow(new ApiConnectionException('Stripe is down'));

    expect(fn () => (new SendSubscriptionCancelledEmailJob(cancelledCustomer()))->handle())
        ->toThrow(ApiConnectionException::class);

    Mail::assertNothingQueued();
    expect(UserMailLog::query()->count())->toBe(0);
});

test('a customer who never paid gets the trial note even if they cancelled it themselves', function () {
    bindStripeBillingHistory([0], cancellationReason: 'cancellation_requested');

    (new SendSubscriptionCancelledEmailJob(cancelledCustomer()))->handle();

    Mail::assertQueued(CancelledBeforePayingEmail::class);
    Mail::assertNotQueued(CancelledAfterPayingEmail::class);
});

test('a customer whose only paid invoice is the zero-euro trial one gets the no-discount note', function () {
    bindStripeBillingHistory([0]);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueued(CancelledBeforePayingEmail::class, fn ($mail) => $mail->hasTo($user->email));
    Mail::assertNotQueued(SubscriptionCancelledEmail::class);
});

test('a customer with no paid invoice at all gets the no-discount note', function () {
    bindStripeBillingHistory([]);

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
    bindStripeBillingHistory([399]);
    $user = cancelledCustomer();

    (new SendSubscriptionCancelledEmailJob($user))->handle();
    (new SendSubscriptionCancelledEmailJob($user))->handle();

    Mail::assertQueuedCount(1);
    expect(UserMailLog::query()->where('user_id', $user->id)->where('email_type', DripEmailType::SubscriptionCancelled)->count())->toBe(1);
});

test('nobody still on a plan is told they left', function (string $status) {
    bindStripeBillingHistory([399]);

    (new SendSubscriptionCancelledEmailJob(cancelledCustomer($status)))->handle();

    Mail::assertNothingQueued();
})->with(['active', 'trialing', 'past_due']);

test('a plan cancelled but still inside its paid period is not written to yet', function () {
    bindStripeBillingHistory([399]);
    $user = cancelledCustomer('active');
    $user->subscription('default')->update(['ends_at' => now()->addWeek()]);

    (new SendSubscriptionCancelledEmailJob($user->fresh()))->handle();

    Mail::assertNothingQueued();
});

test('the shared accounts are never written to', function (User $user) {
    bindStripeBillingHistory([399]);

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
    bindStripeBillingHistory([399]);
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

test('the note for a paying customer who cancelled asks what was missing, without the trial wording or a code', function () {
    $user = User::factory()->create(['name' => 'Marta']);
    $mail = new CancelledAfterPayingEmail($user);

    $body = $mail->render();

    expect($mail->envelope()->subject)->toBe('What was missing?')
        ->and($mail->envelope()->replyTo)->not->toBeEmpty()
        ->and($body)
        ->toContain('Hey Marta, what was missing?')
        ->toContain('We saw you cancelled your subscription')
        ->toContain('reply to this email')
        ->not->toContain('before your first payment')
        ->not->toContain('COMEBACK30')
        ->not->toContain('% off')
        ->toContain('Stop receiving news and offers');
});

test('every variant is translated to Spanish and French', function () {
    $user = User::factory()->create(['name' => 'Marta']);

    app()->setLocale('es');
    expect((new CancelledBeforePayingEmail($user))->render())->toContain('Hola Marta, ¿qué te faltó?')
        ->and((new CancelledAfterPayingEmail($user))->render())->toContain('Hemos visto que has cancelado tu suscripción')
        ->toContain('Gracias por el tiempo que has pasado con Whisper Money')
        ->and((new SubscriptionCancelledEmail($user))->render())->toContain('COMEBACK30</strong> para obtener un');

    app()->setLocale('fr');
    expect((new CancelledAfterPayingEmail($user))->render())->toContain('vous avez résilié votre abonnement')
        ->toContain('Merci pour le temps passé avec Whisper Money');
});

test('the cancellation email counts as marketing while the trial-ending warning stays operational', function () {
    expect(DripEmailType::SubscriptionCancelled->isMarketing())->toBeTrue()
        ->and(DripEmailType::TrialEnding->isMarketing())->toBeFalse();
});

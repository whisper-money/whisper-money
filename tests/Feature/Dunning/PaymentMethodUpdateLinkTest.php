<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Mail\Drip\PaymentActionRequiredEmail;
use App\Mail\Drip\PaymentFailedEmail;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Mail;
use Stripe\Exception\InvalidRequestException;

/**
 * A user whose portal redirect is stubbed, so no Stripe call is made.
 */
function portalUser(): User
{
    $user = Mockery::mock(User::class)->shouldIgnoreMissing();
    $user->shouldReceive('isDemoAccount')->andReturn(false);
    $user->shouldReceive('hasStripeId')->andReturn(true);

    return $user;
}

test('the payment method flow opens the portal straight on updating the card', function () {
    $user = portalUser();
    $user->shouldReceive('redirectToBillingPortal')
        ->once()
        ->with(route('settings.billing'), Mockery::on(fn (array $options) => $options['flow_data']['type'] === 'payment_method_update'
            && $options['flow_data']['after_completion']['redirect']['return_url'] === route('settings.billing')))
        ->andReturn(new RedirectResponse('https://billing.stripe.com/flow'));

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('settings.billing.portal', ['flow' => 'payment_method_update']))
        ->assertRedirect('https://billing.stripe.com/flow');
});

test('a portal configuration that refuses the flow falls back to the portal home', function () {
    $user = portalUser();
    $user->shouldReceive('redirectToBillingPortal')
        ->once()
        ->with(route('settings.billing'), Mockery::type('array'))
        ->andThrow(new InvalidRequestException('flow disabled'));
    $user->shouldReceive('redirectToBillingPortal')
        ->once()
        ->with(route('settings.billing'))
        ->andReturn(new RedirectResponse('https://billing.stripe.com/home'));

    $this->withoutMiddleware(HandleInertiaRequests::class);
    $this->actingAs($user);

    $this->get(route('settings.billing.portal', ['flow' => 'payment_method_update']))
        ->assertRedirect('https://billing.stripe.com/home');
});

test('a logged-out reader is asked to log in, not to sign up, and lands back on the flow', function () {
    $url = route('settings.billing.portal', ['flow' => 'payment_method_update', 'utm_content' => 'first']);

    $this->get($url)->assertRedirect(route('login'));

    expect(session('url.intended'))->toBe($url);
});

test('logging in on the way to the portal leaves Inertia with a full page visit', function () {
    $user = User::factory()->withoutTwoFactor()->create();
    $url = route('settings.billing.portal', ['flow' => 'payment_method_update']);

    // The portal route redirects on to Stripe, a cross-origin hop the login
    // form's XHR cannot follow.
    $this->withSession(['url.intended' => $url])
        ->withHeaders(['X-Inertia' => 'true'])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertStatus(409)
        ->assertHeader('X-Inertia-Location', $url);
});

test('email:test sends the dunning emails', function () {
    Mail::fake();

    $this->artisan('email:test', ['email' => 'dunning-test@example.com'])->assertSuccessful();

    Mail::assertSent(PaymentFailedEmail::class, 4);
    Mail::assertSent(PaymentActionRequiredEmail::class, 2);
});

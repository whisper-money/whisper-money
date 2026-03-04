<?php

use App\Mail\WaitlistReferralNotification;
use App\Mail\WaitlistWelcome;
use App\Models\UserLead;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

test('user lead is created with position starting at 500', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'first@example.com',
    ]);

    $lead = UserLead::where('email', 'first@example.com')->first();
    expect($lead)->not->toBeNull();
    expect($lead->position)->toBe(500);
});

test('each subsequent lead gets a higher position', function () {
    UserLead::factory()->create(['position' => 500]);

    $this->post(route('user-leads.store'), ['email' => 'second@example.com']);

    $lead = UserLead::where('email', 'second@example.com')->first();
    expect($lead->position)->toBe(501);
});

test('user lead gets a referral code on creation', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    $lead = UserLead::where('email', 'test@example.com')->first();
    expect($lead->referral_code)->not->toBeEmpty();
    expect(strlen($lead->referral_code))->toBe(8);
});

test('user lead referral url is correct', function () {
    $lead = UserLead::factory()->create(['referral_code' => 'TESTCODE']);

    expect($lead->referral_url)->toContain('?ref=TESTCODE');
});

test('user lead redirects to the thank you page', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'test@example.com',
    ]);

    $lead = UserLead::where('email', 'test@example.com')->first();
    $response->assertRedirect(route('waitlist.thank-you', $lead));
});

test('welcome email is sent when a user lead is created', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    Mail::assertQueued(WaitlistWelcome::class, function (WaitlistWelcome $mail) {
        return $mail->hasTo('test@example.com');
    });
});

test('referrer moves forward 10 positions when someone uses their link', function () {
    $referrer = UserLead::factory()->create(['position' => 510]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    expect($referrer->fresh()->position)->toBe(500);
});

test('referrer position cannot go below 1', function () {
    $referrer = UserLead::factory()->create(['position' => 5]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    expect($referrer->fresh()->position)->toBe(1);
});

test('referral notification email is sent to the referrer', function () {
    $referrer = UserLead::factory()->create(['position' => 510]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    Mail::assertQueued(WaitlistReferralNotification::class, function (WaitlistReferralNotification $mail) use ($referrer) {
        return $mail->hasTo($referrer->email);
    });
});

test('new lead is linked to the referrer', function () {
    $referrer = UserLead::factory()->create();

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $newLead = UserLead::where('email', 'new@example.com')->first();
    expect($newLead->referred_by_id)->toBe($referrer->id);
});

test('invalid referrer code is silently ignored', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'test@example.com',
        'referrer_code' => 'BADCODE1',
    ]);

    $lead = UserLead::where('email', 'test@example.com')->first();
    expect($lead)->not->toBeNull();
    expect($lead->referred_by_id)->toBeNull();
    Mail::assertQueued(WaitlistWelcome::class);
    Mail::assertNotQueued(WaitlistReferralNotification::class);
});

test('user lead cannot be created with duplicate email', function () {
    UserLead::factory()->create(['email' => 'test@example.com']);

    $response = $this->post(route('user-leads.store'), [
        'email' => 'test@example.com',
    ]);

    $response->assertSessionHasErrors('email');
});

test('user lead requires valid email', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'invalid-email',
    ]);

    $response->assertSessionHasErrors('email');
});

test('thank you page shows position and referral url', function () {
    $lead = UserLead::factory()->create([
        'position' => 500,
        'referral_code' => 'TESTCODE',
    ]);

    $response = $this->withoutVite()->get(route('waitlist.thank-you', $lead));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('waitlist/thank-you')
        ->where('position', 500)
        ->where('referralUrl', fn ($url) => str_contains($url, '?ref=TESTCODE'))
    );
});

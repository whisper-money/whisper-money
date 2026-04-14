<?php

use App\Mail\WaitlistOvertaken;
use App\Mail\WaitlistReferralNotification;
use App\Mail\WaitlistWelcome;
use App\Models\UserLead;
use App\Notifications\VerifyUserLeadEmailNotification;
use Illuminate\Auth\Events\Verified;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

beforeEach(function () {
    Mail::fake();
    Notification::fake();
});

test('user lead is created as unverified and pending confirmation', function () {
    $response = $this->post(route('user-leads.store'), [
        'email' => 'first@example.com',
    ]);

    $lead = UserLead::where('email', 'first@example.com')->first();

    expect($lead)->not->toBeNull();
    expect($lead->email_verified_at)->toBeNull();
    expect($lead->position)->toBeNull();
    expect($lead->referral_code)->toBeNull();

    $response->assertRedirect(route('waitlist.check-email', $lead));
});

test('verification email is sent when a user lead is created', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    $lead = UserLead::where('email', 'test@example.com')->firstOrFail();

    Notification::assertSentTo($lead, VerifyUserLeadEmailNotification::class);
    Mail::assertNothingQueued();
});

test('verified lead gets a position starting at 500', function () {
    $this->post(route('user-leads.store'), ['email' => 'first@example.com']);

    $lead = UserLead::where('email', 'first@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($lead->fresh()->position)->toBe(500);
});

test('each subsequent verified lead gets a higher position', function () {
    UserLead::factory()->create(['position' => 500]);

    $this->post(route('user-leads.store'), ['email' => 'second@example.com']);

    $lead = UserLead::where('email', 'second@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($lead->fresh()->position)->toBe(501);
});

test('user lead gets a referral code after verification', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    $lead = UserLead::where('email', 'test@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($lead->fresh()->referral_code)->not->toBeEmpty();
    expect(strlen($lead->fresh()->referral_code))->toBe(8);
});

test('user lead referral url is correct', function () {
    $lead = UserLead::factory()->create(['referral_code' => 'TESTCODE']);

    expect($lead->referral_url)->toContain('?ref=TESTCODE');
});

test('verified user lead redirects to the thank you page', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    $lead = UserLead::where('email', 'test@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl)
        ->assertRedirect(route('waitlist.thank-you', $lead));
});

test('welcome email is sent when a user lead verifies their email', function () {
    $this->post(route('user-leads.store'), ['email' => 'test@example.com']);

    $lead = UserLead::where('email', 'test@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Mail::assertQueued(WaitlistWelcome::class, function (WaitlistWelcome $mail) {
        return $mail->hasTo('test@example.com');
    });
});

test('lead verification dispatches the verified event', function () {
    $this->post(route('user-leads.store'), ['email' => 'verified-test@example.com']);

    $lead = UserLead::where('email', 'verified-test@example.com')->firstOrFail();

    Event::fake([Verified::class]);

    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Event::assertDispatched(Verified::class);
});

test('referrer moves forward 10 positions when a verified lead uses their link', function () {
    $referrer = UserLead::factory()->create(['position' => 510]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($referrer->fresh()->position)->toBe(500);
});

test('unverified referred lead does not move referrer forward yet', function () {
    $referrer = UserLead::factory()->create(['position' => 510]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    expect($referrer->fresh()->position)->toBe(510);
});

test('referrer position cannot go below 1', function () {
    $referrer = UserLead::factory()->create(['position' => 5]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($referrer->fresh()->position)->toBe(1);
});

test('referral notification email is sent to the referrer after verification', function () {
    $referrer = UserLead::factory()->create(['position' => 510]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

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

    $newLead = UserLead::where('email', 'new@example.com')->firstOrFail();
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
    Notification::assertSentTo($lead, VerifyUserLeadEmailNotification::class);
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

test('user lead stores the locale submitted with the form', function () {
    $this->post(route('user-leads.store'), [
        'email' => 'test@example.com',
        'locale' => 'es',
    ]);

    $lead = UserLead::where('email', 'test@example.com')->first();
    expect($lead->locale)->toBe('es');
});

test('user lead defaults to app locale when no locale is submitted', function () {
    $this->post(route('user-leads.store'), [
        'email' => 'test@example.com',
    ]);

    $lead = UserLead::where('email', 'test@example.com')->first();
    expect($lead->locale)->toBe(app()->getLocale());
});

test('check email page shows the pending email address', function () {
    $lead = UserLead::factory()->unverified()->create(['email' => 'pending@example.com']);

    $response = $this->withoutVite()->get(route('waitlist.check-email', $lead));

    $response->assertSuccessful();
    $response->assertInertia(fn ($page) => $page
        ->component('waitlist/check-email')
        ->where('email', 'pending@example.com')
    );
});

test('verification link with invalid hash does not verify the lead', function () {
    $lead = UserLead::factory()->unverified()->create(['email' => 'pending@example.com']);

    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1('wrong-email')],
    );

    $this->get($verificationUrl)
        ->assertSessionHasErrors('email');

    expect($lead->fresh()->hasVerifiedEmail())->toBeFalse();
});

test('overtaken email is sent using the overtaken lead locale', function () {
    $referrer = UserLead::factory()->create(['position' => 520]);
    $between = UserLead::factory()->create(['position' => 515, 'locale' => 'es']);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Mail::assertQueued(WaitlistOvertaken::class, function (WaitlistOvertaken $mail) use ($between) {
        return $mail->hasTo($between->email) && $mail->locale === 'es';
    });
});

test('overtaken leads are pushed back one position when referrer jumps forward', function () {
    $referrer = UserLead::factory()->create(['position' => 520]);
    $between1 = UserLead::factory()->create(['position' => 511]);
    $between2 = UserLead::factory()->create(['position' => 515]);
    $outsideRange = UserLead::factory()->create(['position' => 525]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    expect($between1->fresh()->position)->toBe(512);
    expect($between2->fresh()->position)->toBe(516);
    expect($outsideRange->fresh()->position)->toBe(525);
});

test('overtaken leads receive the overtaken email', function () {
    $referrer = UserLead::factory()->create(['position' => 520]);
    $between = UserLead::factory()->create(['position' => 515]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Mail::assertQueued(WaitlistOvertaken::class, function (WaitlistOvertaken $mail) use ($between) {
        return $mail->hasTo($between->email);
    });
});

test('referrer does not receive the overtaken email', function () {
    $referrer = UserLead::factory()->create(['position' => 520]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Mail::assertNotQueued(WaitlistOvertaken::class, function (WaitlistOvertaken $mail) use ($referrer) {
        return $mail->hasTo($referrer->email);
    });
});

test('no overtaken emails when referrer is alone in their range', function () {
    $referrer = UserLead::factory()->create(['position' => 520]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    Mail::assertNotQueued(WaitlistOvertaken::class);
});

test('clamped referrer only pushes back leads within the actual range', function () {
    $referrer = UserLead::factory()->create(['position' => 5]);
    $withinRange = UserLead::factory()->create(['position' => 3]);
    $atOne = UserLead::factory()->create(['position' => 1]);

    $this->post(route('user-leads.store'), [
        'email' => 'new@example.com',
        'referrer_code' => $referrer->referral_code,
    ]);

    $lead = UserLead::where('email', 'new@example.com')->firstOrFail();
    $verificationUrl = URL::temporarySignedRoute(
        'user-leads.verify',
        now()->addMinutes(60),
        ['lead' => $lead->id, 'hash' => sha1(strtolower($lead->email))],
    );

    $this->get($verificationUrl);

    // Referrer clamps to 1, overtaken range is positions 1–4
    expect($referrer->fresh()->position)->toBe(1);
    expect($withinRange->fresh()->position)->toBe(4);
    expect($atOne->fresh()->position)->toBe(2);
});

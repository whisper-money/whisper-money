<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendAiConsentFollowUpEmailJob;
use App\Mail\Drip\AiConsentFollowUpEmail;
use App\Models\AiConsent;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

// Freeze time so day-relative timestamps land on exact boundaries; without this the
// two now() calls below drift by microseconds and tip the "exactly 3 days" case over.
beforeEach(fn () => $this->freezeTime());

/**
 * Creates a user whose AI consent was recorded $acceptedDaysAgo days ago, having
 * signed up $signedUpDaysAgo days ago.
 */
function userWithConsent(int $signedUpDaysAgo, int $acceptedDaysAgo): User
{
    $user = User::factory()->create(['created_at' => now()->subDays($signedUpDaysAgo)]);

    $user->aiConsents()->create([
        'scope' => AiConsent::SCOPE_FINANCE,
        'version' => (string) config('ai_suggestions.consent_version'),
        'accepted_at' => now()->subDays($acceptedDaysAgo),
    ]);

    return $user;
}

it('queues the follow-up for a post-onboarding consent accepted two days ago', function () {
    Bus::fake();

    $user = userWithConsent(signedUpDaysAgo: 10, acceptedDaysAgo: 2);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertDispatched(
        SendAiConsentFollowUpEmailJob::class,
        fn (SendAiConsentFollowUpEmailJob $job): bool => $job->user->is($user),
    );
});

it('skips consent given during onboarding', function () {
    Bus::fake();

    // Signed up and consented the same day → onboarding, not a deliberate opt-in.
    userWithConsent(signedUpDaysAgo: 2, acceptedDaysAgo: 2);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertNotDispatched(SendAiConsentFollowUpEmailJob::class);
});

it('treats consent exactly three days after signup as onboarding', function () {
    Bus::fake();

    // accepted two days ago, signed up five days ago → exactly 3 days apart (not > 3).
    userWithConsent(signedUpDaysAgo: 5, acceptedDaysAgo: 2);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertNotDispatched(SendAiConsentFollowUpEmailJob::class);
});

it('does not queue when the consent was not accepted exactly two days ago', function () {
    Bus::fake();

    userWithConsent(signedUpDaysAgo: 10, acceptedDaysAgo: 1);
    userWithConsent(signedUpDaysAgo: 10, acceptedDaysAgo: 3);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertNotDispatched(SendAiConsentFollowUpEmailJob::class);
});

it('skips revoked consent', function () {
    Bus::fake();

    $user = userWithConsent(signedUpDaysAgo: 10, acceptedDaysAgo: 2);
    $user->aiConsents()->update(['revoked_at' => now()]);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertNotDispatched(SendAiConsentFollowUpEmailJob::class);
});

it('does nothing when drip emails are disabled', function () {
    config(['mail.drip_emails_enabled' => false]);
    Bus::fake();

    userWithConsent(signedUpDaysAgo: 10, acceptedDaysAgo: 2);

    $this->artisan('email:ai-consent-follow-up')->assertSuccessful();

    Bus::assertNotDispatched(SendAiConsentFollowUpEmailJob::class);
});

it('sends the email once and records a mail log', function () {
    Mail::fake();

    $user = User::factory()->has(AiConsent::factory(), 'aiConsents')->create();

    (new SendAiConsentFollowUpEmailJob($user))->handle();

    Mail::assertQueued(AiConsentFollowUpEmail::class);
    expect($user->hasReceivedEmail(DripEmailType::AiConsentFollowUp))->toBeTrue();
    expect(UserMailLog::where('user_id', $user->id)->count())->toBe(1);
});

it('renders the email in the user locale', function () {
    app()->setLocale('es');

    $user = User::factory()->make(['name' => 'Ada']);
    $html = (new AiConsentFollowUpEmail($user))->render();

    app()->setLocale('en');

    expect($html)->toContain('Tu asistente de IA está trabajando duro');
    expect($html)->toContain('category_source=ai');
});

it('does not resend if the user already received it', function () {
    Mail::fake();

    $user = User::factory()->has(AiConsent::factory(), 'aiConsents')->create();
    UserMailLog::create([
        'user_id' => $user->id,
        'email_type' => DripEmailType::AiConsentFollowUp,
        'email_identifier' => DripEmailType::AiConsentFollowUp->value,
        'sent_at' => now(),
    ]);

    (new SendAiConsentFollowUpEmailJob($user))->handle();

    Mail::assertNotQueued(AiConsentFollowUpEmail::class);
});

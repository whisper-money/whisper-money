<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendFeedbackEmailJob;
use App\Jobs\Drip\SendImportHelpEmailJob;
use App\Jobs\Drip\SendOnboardingReminderEmailJob;
use App\Jobs\Drip\SendPaywallFollowUpEmailJob;
use App\Jobs\Drip\SendPromoCodeEmailJob;
use App\Jobs\Drip\SendSubscriptionCancelledEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Mail\Drip\FeedbackEmail;
use App\Mail\Drip\ImportHelpEmail;
use App\Mail\Drip\OnboardingReminderEmail;
use App\Mail\Drip\PaywallFollowUpEmail;
use App\Mail\Drip\PromoCodeEmail;
use App\Mail\Drip\SubscriptionCancelledEmail;
use App\Mail\Drip\WelcomeEmail;
use App\Models\BankingConnection;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

/*
 * The guards every drip email shares with SendDripEmailJob, run once per job
 * with a user that passes that job's own eligibility checks. What makes each
 * job eligible or not stays in the job's own test file.
 */

beforeEach(function () {
    Mail::fake();
    config(['subscriptions.enabled' => true]);
});

$subscriptionGatedJobs = [
    'feedback' => [SendFeedbackEmailJob::class, FeedbackEmail::class, DripEmailType::Feedback,
        fn (): User => User::factory()->create()],
    'import help' => [SendImportHelpEmailJob::class, ImportHelpEmail::class, DripEmailType::ImportHelp,
        fn (): User => User::factory()->onboarded()->create()],
    'paywall follow-up' => [SendPaywallFollowUpEmailJob::class, PaywallFollowUpEmail::class, DripEmailType::PaywallFollowUp,
        function (): User {
            $user = User::factory()->onboarded()->create();
            BankingConnection::factory()->for($user)->create();

            return $user;
        }],
    'promo code' => [SendPromoCodeEmailJob::class, PromoCodeEmail::class, DripEmailType::PromoCode,
        function (): User {
            $user = User::factory()->onboarded()->create();
            Transaction::factory()->for($user)->create();

            return $user;
        }],
];

dataset('drip email jobs', [
    'welcome' => [SendWelcomeEmailJob::class, WelcomeEmail::class, DripEmailType::Welcome,
        fn (): User => User::factory()->create()],
    'onboarding reminder' => [SendOnboardingReminderEmailJob::class, OnboardingReminderEmail::class, DripEmailType::OnboardingReminder,
        fn (): User => User::factory()->notOnboarded()->create()],
    'subscription cancelled' => [SendSubscriptionCancelledEmailJob::class, SubscriptionCancelledEmail::class, DripEmailType::SubscriptionCancelled,
        fn (): User => User::factory()->create()],
    ...$subscriptionGatedJobs,
]);

dataset('drip email jobs gated by subscription', $subscriptionGatedJobs);

test('the email is sent from the founders and logged', function (string $job, string $mail, DripEmailType $type, Closure $eligibleUser) {
    $user = $eligibleUser();

    $job::dispatchSync($user);

    Mail::assertQueued($mail, fn ($queued) => $queued->hasTo($user->email)
        && $queued->hasFrom('hi@whisper.money', 'Álvaro and Víctor'));

    $this->assertDatabaseHas('user_mail_logs', [
        'user_id' => $user->id,
        'email_type' => $type->value,
    ]);
})->with('drip email jobs');

test('the email is not sent if already received', function (string $job, string $mail, DripEmailType $type, Closure $eligibleUser) {
    $user = $eligibleUser();
    UserMailLog::factory()->for($user)->create(['email_type' => $type, 'email_identifier' => $type->value]);

    $job::dispatchSync($user);

    Mail::assertNotQueued($mail);
})->with('drip email jobs');

test('the email is not sent to subscribed users', function (string $job, string $mail, DripEmailType $type, Closure $eligibleUser) {
    $user = $eligibleUser();
    $user->subscriptions()->create([
        'type' => 'default',
        'stripe_id' => 'sub_test123',
        'stripe_status' => 'active',
        'stripe_price' => 'price_test123',
    ]);

    $job::dispatchSync($user);

    Mail::assertNotQueued($mail);
})->with('drip email jobs gated by subscription');

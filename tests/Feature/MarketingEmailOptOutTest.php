<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendTrialEndingEmailJob;
use App\Jobs\Drip\SendWelcomeEmailJob;
use App\Jobs\SendUpdateEmailJob;
use App\Mail\Drip\WelcomeEmail;
use App\Mail\UpdateEmail;
use App\Models\User;
use App\Models\UserSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;

/**
 * "Product news and offers" switched off. Everything below asserts against a
 * real user row, because the default lives on the column and the absence of a
 * settings row has to read as "yes" just as loudly as the column does.
 */
function userWithoutMarketing(): User
{
    $user = User::factory()->create();

    UserSetting::factory()->for($user)->create(['notify_marketing' => false]);

    return $user->fresh();
}

it('sends a marketing drip while the category is on', function (): void {
    Mail::fake();

    (new SendWelcomeEmailJob(User::factory()->create()))->handle();

    Mail::assertQueued(WelcomeEmail::class);
});

it('sends no marketing drip once the category is off', function (): void {
    Mail::fake();

    (new SendWelcomeEmailJob(userWithoutMarketing()))->handle();

    Mail::assertNothingQueued();
});

it('leaves an unsent marketing drip unlogged, so it still goes out if the reader changes their mind', function (): void {
    Mail::fake();
    $user = userWithoutMarketing();

    (new SendWelcomeEmailJob($user))->handle();

    expect($user->mailLogs()->where('email_type', DripEmailType::Welcome)->exists())->toBeFalse();

    $user->setting->update(['notify_marketing' => true]);

    (new SendWelcomeEmailJob($user->fresh()))->handle();

    Mail::assertQueued(WelcomeEmail::class);
});

it('still sends billing mail once the category is off', function (): void {
    Mail::fake();

    (new SendTrialEndingEmailJob(userWithoutMarketing(), CarbonImmutable::now()->addDays(3), 899, 'eur'))->handle();

    Mail::assertQueuedCount(1);
});

it('sends no update campaign once the category is off', function (): void {
    Mail::fake();

    (new SendUpdateEmailJob(userWithoutMarketing(), 'mcp-launch-aug-2026', 'mcp-launch-aug-2026'))->handle();

    Mail::assertNothingQueued();
});

it('still sends an operational update once the category is off', function (): void {
    Mail::fake();

    (new SendUpdateEmailJob(userWithoutMarketing(), 'encrypted-data-removal', 'encrypted-data-removal', marketing: false))->handle();

    Mail::assertQueued(UpdateEmail::class);
});

/**
 * A job queued before `marketing` existed comes back without it, because
 * Laravel's `SerializesModels::__unserialize()` skips whatever the payload lacks. It
 * has to restore as a campaign, so the opt-out still wins and the read does not
 * throw on an uninitialized typed property.
 */
it('treats an update queued before the flag existed as a campaign', function (): void {
    Mail::fake();

    $values = (new SendUpdateEmailJob(userWithoutMarketing(), 'mcp-launch-aug-2026', 'mcp-launch-aug-2026'))->__serialize();

    unset($values['marketing']);

    $stale = (new ReflectionClass(SendUpdateEmailJob::class))->newInstanceWithoutConstructor();
    $stale->__unserialize($values);

    $stale->handle();

    expect($stale->marketing)->toBeTrue();
    Mail::assertNothingQueued();
});

it('carries a one-click unsubscribe header and a footer link on a marketing drip', function (): void {
    $user = User::factory()->create();
    $mail = new WelcomeEmail($user);

    expect($mail->headers()->text)->toHaveKey('List-Unsubscribe')
        ->and($mail->headers()->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($mail->headers()->text['List-Unsubscribe'])->toContain('unsubscribe/marketing')
        ->and($mail->render())->toContain('Stop receiving news and offers');
});

it('carries the same link on an update campaign', function (): void {
    $mail = new UpdateEmail(User::factory()->create(), 'mcp-launch-aug-2026');

    expect($mail->headers()->text['List-Unsubscribe'])->toContain('unsubscribe/marketing')
        ->and($mail->render())->toContain('Stop receiving news and offers');
});

it('offers no unsubscribe on an operational update', function (): void {
    $mail = new UpdateEmail(User::factory()->create(), 'encrypted-data-removal', marketing: false);

    expect($mail->headers()->text)->toBeEmpty();
});

it('turns product news off from the signed link without a login', function (): void {
    $user = User::factory()->create();

    $this->get(URL::signedRoute('marketing.unsubscribe', ['user' => $user->id]))
        ->assertOk()
        ->assertSee('Product news and offers turned off', false);

    expect($user->fresh()->wantsMarketingEmails())->toBeFalse();
});

it('answers a one-click POST with an empty 200', function (): void {
    $user = User::factory()->create();

    $this->post(URL::signedRoute('marketing.unsubscribe', ['user' => $user->id]))
        ->assertOk()
        ->assertContent('');

    expect($user->fresh()->wantsMarketingEmails())->toBeFalse();
});

it('switches off nothing but product news', function (): void {
    $user = User::factory()->create();

    $this->get(URL::signedRoute('marketing.unsubscribe', ['user' => $user->id]))->assertOk();

    $user = $user->fresh();

    expect($user->wantsMarketingEmails())->toBeFalse()
        ->and($user->wantsMonthlySummaryEmail())->toBeTrue()
        ->and($user->wantsAchievementsEmail())->toBeTrue()
        ->and($user->wantsBankTransactionsSyncedEmail())->toBeTrue()
        ->and($user->wantsInactiveNoBankEmail())->toBeTrue();
});

it('refuses an unsigned unsubscribe link', function (): void {
    $user = User::factory()->create();

    $this->get(route('marketing.unsubscribe', ['user' => $user->id]))->assertForbidden();

    expect($user->fresh()->wantsMarketingEmails())->toBeTrue();
});

it('queues a campaign as marketing by default', function (): void {
    Queue::fake();
    User::factory()->create();

    $this->artisan('email:update', ['view' => 'mcp-launch-aug-2026', '--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job): bool => $job->marketing);
});

it('queues a campaign as a notice when told it is operational', function (): void {
    Queue::fake();
    User::factory()->create();

    $this->artisan('email:update', ['view' => 'encrypted-data-removal', '--operational' => true, '--force' => true])
        ->assertSuccessful();

    Queue::assertPushed(SendUpdateEmailJob::class, fn (SendUpdateEmailJob $job): bool => ! $job->marketing);
});

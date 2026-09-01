<?php

use App\Enums\DripEmailType;
use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Models\UserMailLog;
use Illuminate\Support\Facades\Mail;

/*
 * The report email itself: what it says, who sees the analysis, and how a reader
 * gets out of it.
 */

/**
 * A summary belonging to a fresh reader, with every section filled.
 */
function sentSummaryFor(?User $user = null): MonthlySummary
{
    $user ??= User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    return MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);
}

it('prints the month\'s figures and the things to do', function (): void {
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail($summary->user, $summary))->render();

    expect($rendered)
        ->toContain('35.5%')                       // the headline savings rate
        ->toContain('Trip to Japan')               // the goal row
        ->toContain('12')                          // uncategorised transactions
        ->toContain('BBVA')                        // the expiring connection
        ->toContain('whisper.money');
});

it('shows the analysis to a reader who has one, with the boundary spelled out', function (): void {
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail(
        $summary->user,
        $summary,
        analysis: "It came from spending less.\n\nHousing is the one that will repeat.",
        pro: true,
    ))->render();

    expect($rendered)
        ->toContain('It came from spending less.')
        ->toContain('Housing is the one that will repeat.')
        ->toContain('never from your individual transactions');
});

it('locks the analysis behind the same block for everyone without one', function (): void {
    config(['subscriptions.enabled' => true]);
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail($summary->user, $summary))->render();

    expect($rendered)
        ->toContain('Pro tells you where from')
        ->toContain('See Pro')
        ->not->toContain('never from your individual transactions');
});

it('points a paying reader at the AI setting instead of at the paywall', function (): void {
    // A Pro reader who never granted AI consent gets the same locked block, but
    // sending them to a "See Pro" button for something they already pay for
    // would be absurd. Billing off means everyone is Pro, which is this branch.
    config(['subscriptions.enabled' => false]);
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail($summary->user, $summary))->render();

    expect($rendered)->toContain('Turn AI on in Settings')->not->toContain('See Pro');
});

it('says so when the month was reported incomplete', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
        'complete' => false,
    ]);

    $rendered = (new MonthlySummaryEmail($user, $summary))->render();

    expect($rendered)->toContain('the app has the fuller picture');
});

it('carries a one-click unsubscribe header', function (): void {
    $summary = sentSummaryFor();

    $mail = new MonthlySummaryEmail($summary->user, $summary);
    $headers = $mail->headers();

    expect($headers->text)->toHaveKey('List-Unsubscribe')
        ->and($headers->text['List-Unsubscribe-Post'])->toBe('List-Unsubscribe=One-Click')
        ->and($headers->text['List-Unsubscribe'])->toContain('unsubscribe/monthly-summary');
});

it('names the space only when the reader can see more than one', function (): void {
    Mail::fake();
    $summary = sentSummaryFor();

    (new SendMonthlySummaryEmailJob($summary->user, $summary))->handle();

    Mail::assertQueued(MonthlySummaryEmail::class, fn (MonthlySummaryEmail $mail): bool => $mail->spaceName === null);
});

it('records the send once per month and space', function (): void {
    Mail::fake();
    $summary = sentSummaryFor();

    (new SendMonthlySummaryEmailJob($summary->user, $summary))->handle();
    (new SendMonthlySummaryEmailJob($summary->user, $summary))->handle();

    Mail::assertQueuedCount(1);

    expect(UserMailLog::query()
        ->where('user_id', $summary->user_id)
        ->where('email_type', DripEmailType::MonthlySummary)
        ->where('email_identifier', $summary->period.':'.$summary->space_id)
        ->count())->toBe(1);

    expect($summary->fresh()->sent_at)->not->toBeNull();
});

it('does not send to a reader who turned the summary off', function (): void {
    Mail::fake();
    $summary = sentSummaryFor();
    $summary->user->setting()->updateOrCreate(['user_id' => $summary->user_id], ['notify_monthly_summary' => false]);

    (new SendMonthlySummaryEmailJob($summary->user->fresh(), $summary))->handle();

    Mail::assertNothingQueued();
});

it('turns the summary off from the signed link without a login', function (): void {
    $user = User::factory()->onboarded()->create();
    $url = URL::signedRoute('monthly-summaries.unsubscribe', ['user' => $user->id]);

    $this->get($url)->assertOk()->assertSee('Monthly summary turned off', false);

    expect($user->fresh()->wantsMonthlySummaryEmail())->toBeFalse();
});

it('answers a one-click POST with an empty 200', function (): void {
    $user = User::factory()->onboarded()->create();

    $this->post(URL::signedRoute('monthly-summaries.unsubscribe', ['user' => $user->id]))
        ->assertOk()
        ->assertContent('');

    expect($user->fresh()->wantsMonthlySummaryEmail())->toBeFalse();
});

it('refuses an unsigned unsubscribe link', function (): void {
    $user = User::factory()->onboarded()->create();

    $this->get("/unsubscribe/monthly-summary/{$user->id}")->assertForbidden();

    expect($user->fresh()->wantsMonthlySummaryEmail())->toBeTrue();
});

<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Enums\DripEmailType;
use App\Enums\MonthlySummaryCard;
use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Jobs\WarmMonthlySummaryCardsJob;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Models\UserMailLog;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use App\Services\MonthlySummary\Summaries;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

/*
 * The report email itself: what it says, who sees the analysis, and how a reader
 * gets out of it.
 */

beforeEach(function (): void {
    // What is under test is what the email says, not Chromium: left real, every
    // job test here draws a month's worth of cards.
    $this->mock(CardRenderer::class, function ($mock): void {
        $mock->shouldReceive('warm')->andReturnNull();
        $mock->shouldReceive('forgetBefore')->andReturnNull();
        $mock->shouldReceive('url')->andReturn('https://whisper.money/storage/card.png');
        $mock->shouldReceive('path')->andReturn('monthly-summaries/x/card.png');
        $mock->shouldReceive('forget')->andReturnNull();
    });
});

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

it('says the analysis could not be written rather than selling Pro to someone who has it', function (): void {
    // A provider outage leaves a consenting reader with no analysis, which used
    // to fall into the locked block above: it pitches the plan they already pay
    // for and its button sends them to switch on a setting that is already on.
    config(['subscriptions.enabled' => true]);
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail($summary->user, $summary, pro: true))->render();

    expect($rendered)
        ->toContain('We could not write your analysis this month')
        ->not->toContain('Pro tells you where from')
        ->not->toContain('Turn AI on in Settings')
        ->not->toContain('See Pro');
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

it('leaves the screen\'s other cards to a job of their own', function (): void {
    // The emails worker is one process, so thirty screenshots inside the send
    // are thirty the next reader waits for. Left to the screen instead, a first
    // visit starts a Chromium run for each preview it has not drawn yet, inside
    // as many web requests as the browser opens — hence a job.
    Mail::fake();
    Queue::fake();
    $summary = sentSummaryFor();

    (new SendMonthlySummaryEmailJob($summary->user, $summary))->handle();

    Queue::assertPushedOn(
        'cards',
        WarmMonthlySummaryCardsJob::class,
        fn (WarmMonthlySummaryCardsJob $job): bool => $job->summary->is($summary) && $job->pro === false,
    );
});

it('hands that job the chosen card and every alternative', function (): void {
    $summary = sentSummaryFor();
    $alternatives = app(CardPicker::class)->alternatives($summary->payload, $summary->card);

    expect($alternatives)->not->toBeEmpty();

    $drawn = [];
    $renderer = Mockery::mock(CardRenderer::class);
    $renderer->shouldReceive('forgetBefore')->andReturnNull();
    $renderer->shouldReceive('warm')->andReturnUsing(
        function (MonthlySummary $drawnFor, array $cards) use (&$drawn): void {
            $drawn = array_map(fn (MonthlySummaryCard $card): string => $card->value, $cards);
        }
    );
    app()->instance(CardRenderer::class, $renderer);

    (new WarmMonthlySummaryCardsJob($summary, pro: false))->handle(app(Summaries::class));

    // The chosen card and every alternative, handed over in one run.
    expect($drawn)->toBe([
        $summary->card->value,
        ...array_map(fn (MonthlySummaryCard $card): string => $card->value, $alternatives),
    ]);
});

it('draws the email\'s card in the reader\'s language, not the worker\'s', function (): void {
    // buildMail() runs as the argument to Mail::to()->send(), so the mailer's
    // own switch for a HasLocalePreference recipient comes too late for the
    // picture: it used to come out in the worker's English.
    Mail::fake();
    Queue::fake();
    app()->setLocale('en');
    $summary = sentSummaryFor(User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'locale' => 'es',
    ]));

    $drawnIn = null;
    $renderer = Mockery::mock(CardRenderer::class);
    $renderer->shouldReceive('url')->andReturnUsing(function () use (&$drawnIn): string {
        $drawnIn = app()->getLocale();

        return 'https://whisper.money/storage/card.png';
    });
    app()->instance(CardRenderer::class, $renderer);

    (new SendMonthlySummaryEmailJob($summary->user, $summary))->handle();

    expect($drawnIn)->toBe('es')
        // And the worker is handed back the locale it came in with, so the next
        // reader on the same process is not sent someone else's language.
        ->and(app()->getLocale())->toBe('en');
});

it('draws the screen\'s cards in the reader\'s language too', function (): void {
    app()->setLocale('en');
    $summary = sentSummaryFor(User::factory()->onboarded()->create([
        'currency_code' => 'EUR',
        'locale' => 'es',
    ]));

    $drawnIn = null;
    $renderer = Mockery::mock(CardRenderer::class);
    $renderer->shouldReceive('forgetBefore')->andReturnNull();
    $renderer->shouldReceive('warm')->andReturnUsing(function () use (&$drawnIn): void {
        $drawnIn = app()->getLocale();
    });
    app()->instance(CardRenderer::class, $renderer);

    (new WarmMonthlySummaryCardsJob($summary, pro: false))->handle(app(Summaries::class));

    expect($drawnIn)->toBe('es')->and(app()->getLocale())->toBe('en');
});

it('spends every attempt on a provider that never answers, and sends the report regardless', function (): void {
    // The report has always gone out without the section - a read timeout was
    // caught as an unexpected bug, reported, and the remaining attempts thrown
    // away (PHP-LARAVEL-5Q). What the reader lost was the analysis itself, to a
    // single blip that a second attempt would usually have got past.
    config(['subscriptions.enabled' => false, 'ai_monthly_summary.attempts' => 2]);
    Mail::fake();
    Queue::fake();
    Exceptions::fake();

    $summary = sentSummaryFor();
    $summary->user->recordAiConsent();

    $attempts = 0;
    MonthlySummaryAgent::fake(function () use (&$attempts): never {
        $attempts++;

        throw new ConnectionException(
            'cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received',
        );
    });

    (new SendMonthlySummaryEmailJob($summary->user->fresh(), $summary))->handle();

    // Sent, addressed to a reader the email knows is entitled - which is what
    // keeps the paywall block out of it - and with no analysis.
    Mail::assertQueued(
        MonthlySummaryEmail::class,
        fn (MonthlySummaryEmail $mail): bool => $mail->analysis === null && $mail->pro,
    );

    expect($attempts)->toBe(2)
        ->and($summary->fresh()->sent_at)->not->toBeNull()
        ->and(UserMailLog::where('user_id', $summary->user_id)
            ->where('email_type', DripEmailType::MonthlySummary)
            ->exists())->toBeTrue();
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

it('keeps a bar segment inside the bar it sits in', function (): void {
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);
    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    // A ratio against a near-zero denominator is not a width. This one rendered
    // a segment 349,899,800% wide in a real reader's email.
    $summary->forceFill(['payload' => [
        ...$summary->payload,
        'invested' => ['contributed' => 3498998, 'value' => 1, 'gain' => -3498997, 'currency' => 'EUR'],
    ]])->save();

    $rows = app(EmailPresenter::class)->present($summary->fresh(), 'en');
    $invested = collect($rows['rows'])->firstWhere('viz', 'bar');
    $widths = array_column(collect($rows['rows'])->pluck('data.segments')->flatten(1)->all(), 'width');

    expect($invested)->not->toBeNull()
        ->and(max($widths))->toBeLessThanOrEqual(100)
        ->and(min($widths))->toBeGreaterThanOrEqual(0);
});

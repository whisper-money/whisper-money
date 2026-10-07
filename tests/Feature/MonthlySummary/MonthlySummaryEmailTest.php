<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Enums\DripEmailType;
use App\Enums\MonthlySummaryCard;
use App\Jobs\Drip\SendMonthlySummaryEmailJob;
use App\Jobs\WarmMonthlySummaryCardsJob;
use App\Mail\Drip\MonthlySummaryEmail;
use App\Models\Achievement;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Models\UserMailLog;
use App\Services\MonthlySummary\CardPicker;
use App\Services\MonthlySummary\CardRenderer;
use App\Services\MonthlySummary\EmailPresenter;
use App\Services\MonthlySummary\ReportPresenter;
use App\Services\MonthlySummary\Summaries;
use App\Support\Money;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;

/*
 * The report email itself: what it says, who sees the analysis, and how a reader
 * gets out of it.
 */

beforeEach(function (): void {
    // The analysis backs off between attempts; no test here is about waiting.
    Sleep::fake();

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

it('talks in percentages and points into the report', function (): void {
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail($summary->user, $summary))->render();

    expect($rendered)
        ->toContain('You saved 35.5% of what you earned in')  // the headline
        ->toContain('+2.0%')                                   // net worth tile
        ->toContain('-4.1%')                                   // spending tile
        ->toContain('4 of 6')                                  // budgets tile
        // Three tiles at most: the goal is fourth in line.
        ->not->toContain('Trip to Japan')
        ->toContain('See the full report')
        ->toContain(route('monthly-summaries.show', $summary).'?')
        ->toContain('Inside: every figure in detail and 2 things to close')
        // What used to be printed and now lives only in the app.
        ->not->toContain('The rest of')
        ->not->toContain('BBVA')
        ->not->toContain('Sort them out')
        ->not->toContain('Or share something else');
});

it('keeps every figure, to-do and the analysis in the app report', function (): void {
    config()->set('inertia.ssr.enabled', false);
    $summary = sentSummaryFor();
    $summary->forceFill(['sent_at' => now(), 'ai_analysis' => 'Groceries went up 149 €.'])->save();

    $this->actingAs($summary->user)
        ->get(route('monthly-summaries.show', $summary))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('analysis', 'Groceries went up 149 €.')
            ->where('report.headline', fn (string $headline): bool => str_contains($headline, '35.5%'))
            ->where('report.rows', fn ($rows): bool => collect($rows)->contains(fn (array $row): bool => str_contains($row['text'], '160,223.05')))
            ->where('report.todos', fn ($todos): bool => collect($todos)->contains(fn (array $todo): bool => str_contains($todo['text'], 'BBVA'))));
});

it('says a month that spent more than it earned as a percentage', function (): void {
    $summary = summaryWith(['cashflow' => ['savings_rate' => -12.4, 'net' => -47740, 'income' => 385000]]);

    $email = (new MonthlySummaryEmail($summary->user, $summary, analysis: 'Sample.', pro: true))->render();

    expect($email)
        ->toContain('You spent 12.4% more than you earned in')
        ->toContain('what made you spend more than you earned')
        ->not->toContain('477.40');

    // The app still names the shortfall in money.
    expect(app(ReportPresenter::class)->headline($summary, 'en'))->toContain('477.40');
});

it('leads a month with nothing to measure against with no figure at all', function (): void {
    $summary = summaryWith(['cashflow' => ['savings_rate' => 0.0, 'net' => 0, 'income' => 0]]);

    expect((new MonthlySummaryEmail($summary->user, $summary))->render())
        ->toContain('This is how your '.$summary->periodStart()->isoFormat('MMMM').' went.');
});

it('only compares against a previous month there is one for', function (): void {
    $summary = summaryWith([
        'has_history' => false,
        'budgets' => ['total' => 0, 'met' => 0, 'overspent' => []],
    ]);

    $kpis = app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'];

    // No net worth or spending change on a first month, no budgets set: the
    // goal is the one tile left.
    expect($kpis)->toHaveCount(1)
        ->and($kpis[0])->toMatchArray(['value' => '62.0%', 'label' => 'Trip to Japan', 'sub' => 'of the goal']);
});

it('skips a change measured against a base that is zero or negative', function (): void {
    $summary = summaryWith([
        'net_worth' => ['previous' => -50000, 'diff_percent' => 120.0],
        'cashflow' => ['previous' => ['expense' => 0]],
        'goal' => null,
    ]);

    $labels = array_column(app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'], 'label');

    expect($labels)->toBe(['Budgets']);
});

it('drops the strip when no tile has anything to say', function (): void {
    $summary = summaryWith([
        'has_history' => false,
        'budgets' => ['total' => 0, 'met' => 0, 'overspent' => []],
        'goal' => null,
    ]);

    expect((new MonthlySummaryEmail($summary->user, $summary))->render())->not->toContain('font-size:22px')
        ->and(app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'])->toBe([]);
});

it('colours a change by whether it is good news', function (): void {
    $summary = summaryWith([
        'net_worth' => ['diff_percent' => -0.9],
        'cashflow' => ['expense_change_percent' => 18.2],
    ]);

    $kpis = app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'];

    expect(array_column($kpis, 'tone'))->toBe(['bad', 'bad', null]);
});

it('leaves a change that rounds to nothing unsigned and uncoloured', function (): void {
    $summary = summaryWith(['net_worth' => ['diff_percent' => 0.04]]);

    $tile = app(EmailPresenter::class)->present($summary->user, $summary, 'en', false)['kpis'][0];

    expect($tile)->toMatchArray(['value' => '0.0%', 'tone' => null]);
});

it('counts the month\'s medals in the line under the main button', function (): void {
    $summary = sentSummaryFor();
    Achievement::factory()->key('streaks.1')->create([
        'user_id' => $summary->user_id,
        'space_id' => $summary->space_id,
        'achieved_on' => $summary->periodStart()->toDateString(),
    ]);

    $inside = app(EmailPresenter::class)->present($summary->user, $summary, 'en', true)['inside'];

    expect($inside)->toBe('Inside: every figure in detail, 1 new medal and 3 things to close '.$summary->periodStart()->isoFormat('MMMM').'.');
});

it('tells a reader with an analysis it is waiting in the report, without a word of it', function (): void {
    $summary = sentSummaryFor();

    $rendered = (new MonthlySummaryEmail(
        $summary->user,
        $summary,
        analysis: "It came from spending less.\n\nHousing is the one that will repeat.",
        pro: true,
    ))->render();

    expect($rendered)
        ->toContain('is waiting in the report: what moved that 35.5%')
        ->toContain('Read my analysis')
        ->toContain('utm_content=analysis#analysis')
        ->toContain('never from your individual transactions')
        ->not->toContain('It came from spending less.')
        ->not->toContain('Housing is the one that will repeat.');
});

/*
 * The guard. An email sits in an inbox for years, syncs to every device and
 * shows on lock screens, so the monthly one never carries an absolute amount.
 * If a sentence with money in it is ever added back, this is what fails.
 */
dataset('every email state', [
    'pro with an analysis' => fn (): array => ['analysis' => SAMPLE_ANALYSIS_WITH_AMOUNTS, 'pro' => true, 'payload' => []],
    'pro, model failed' => fn (): array => ['analysis' => null, 'pro' => true, 'payload' => []],
    'free or pro without AI' => fn (): array => ['analysis' => null, 'pro' => false, 'payload' => []],
    'negative month' => fn (): array => ['analysis' => SAMPLE_ANALYSIS_WITH_AMOUNTS, 'pro' => true, 'payload' => ['cashflow' => ['savings_rate' => -12.4, 'net' => -47740]]],
    'first month' => fn (): array => ['analysis' => SAMPLE_ANALYSIS_WITH_AMOUNTS, 'pro' => true, 'payload' => ['has_history' => false]],
]);

const SAMPLE_ANALYSIS_WITH_AMOUNTS = 'Groceries went up 149 € and Transport another 64 €.';

it('never carries an absolute amount', function (array $state): void {
    $summary = summaryWith($state['payload']);
    Achievement::factory()->key('net_worth.4')->create([
        'user_id' => $summary->user_id,
        'space_id' => $summary->space_id,
        'achieved_on' => $summary->periodStart()->toDateString(),
    ]);

    foreach ([true, false] as $subscriptions) {
        config(['subscriptions.enabled' => $subscriptions]);

        $rendered = (new MonthlySummaryEmail($summary->user, $summary, $state['analysis'], 'https://whisper.money/storage/card.png', $state['pro']))->render();

        expect($rendered)->not->toMatch('/[€$£¥]|EUR/u');

        foreach (amountsIn($summary) as $amount) {
            expect($rendered)->not->toContain($amount);
        }
    }
})->with('every email state');

/**
 * The fixture summary, with parts of its payload overridden.
 *
 * @param  array<string, mixed>  $overrides
 */
function summaryWith(array $overrides): MonthlySummary
{
    $summary = sentSummaryFor();
    $summary->forceFill(['payload' => array_replace_recursive($summary->payload, $overrides)])->save();

    return $summary->fresh();
}

/**
 * Every amount the fixture payload holds, as the app would print it, plus the
 * bare number, so neither "1,368.05 €" nor "1,368.05" slips through.
 *
 * @return list<string>
 */
function amountsIn(MonthlySummary $summary): array
{
    $cents = [
        $summary->figure('net_worth.current'), $summary->figure('net_worth.previous'), $summary->figure('net_worth.diff'),
        $summary->figure('cashflow.income'), $summary->figure('cashflow.expense'), abs((int) $summary->figure('cashflow.net')),
        $summary->figure('categories.total'), $summary->figure('categories.top.0.amount'),
        $summary->figure('invested.value'), $summary->figure('invested.gain'), $summary->figure('invested.contributed'),
        $summary->figure('goal.saved'), $summary->figure('goal.target'),
        $summary->figure('todos.uncategorised.amount'),
        $summary->figure('budgets.overspent.0.over_by'), $summary->figure('budgets.overspent.1.over_by'),
        10000000, 25000000, // the net worth medals either side of the fixture's figure
    ];

    return collect($cents)
        ->filter()
        ->flatMap(fn (int $amount): array => [
            Money::formatIn($amount, 'EUR', 'en'),
            Number::format($amount / 100, precision: 2, locale: 'en'),
        ])
        ->values()
        ->all();
}

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

/**
 * A reader entitled to an analysis, and a model that never answers: the 30s
 * Gemini timeout that cost 13 of 97 Pro readers their analysis on the first real
 * send (PHP-LARAVEL-5Q). Billing off makes everyone Pro, so consent is the only
 * gate left to grant.
 */
function readerOwedAnAnalysis(): MonthlySummary
{
    config(['subscriptions.enabled' => false, 'ai_monthly_summary.attempts' => 2]);

    $summary = sentSummaryFor();
    $summary->user->recordAiConsent();

    MonthlySummaryAgent::fake(fn (): never => throw new ConnectionException(
        'cURL error 28: Operation timed out after 30002 milliseconds with 0 bytes received',
    ));

    return $summary;
}

/**
 * The job as the worker runs it. `attempts()` and `release()` mean nothing
 * without a queue job attached, and the decision to hold the report reads both.
 *
 * @return array{0: SendMonthlySummaryEmailJob, 1: MockInterface}
 */
function summaryJobOnAttempt(MonthlySummary $summary, int $attempt): array
{
    $job = new SendMonthlySummaryEmailJob($summary->user->fresh(), $summary);

    $queueJob = Mockery::mock(Job::class);
    $queueJob->shouldReceive('attempts')->andReturn($attempt);
    $job->setJob($queueJob);

    return [$job, $queueJob];
}

it('holds the report rather than handing a paying reader an analysis-less one', function (): void {
    // The analysis has to reach them in the email, not only on the screen, so a
    // provider wobble postpones the send instead of spending the month's one
    // report on a summary with the section missing.
    Mail::fake();
    Queue::fake();
    Exceptions::fake();

    $summary = readerOwedAnAnalysis();
    [$job, $queueJob] = summaryJobOnAttempt($summary, 1);
    $queueJob->shouldReceive('release')->once();

    $job->handle();

    Mail::assertNothingQueued();

    expect($summary->fresh()->sent_at)->toBeNull()
        ->and(UserMailLog::where('user_id', $summary->user_id)
            ->where('email_type', DripEmailType::MonthlySummary)
            ->exists())->toBeFalse();

    // Nothing filed while there are passes left, or one outage would be five
    // Sentry events per reader.
    Exceptions::assertNothingReported();
});

it('sends the report without the analysis once the retries are spent', function (): void {
    // The last permitted attempt must send: another release would exhaust the
    // job and drop it in failed_jobs, and the reader would get no report at all
    // - strictly worse than one carrying the honest "we could not write it".
    Mail::fake();
    Queue::fake();
    Exceptions::fake();

    $summary = readerOwedAnAnalysis();
    [$job, $queueJob] = summaryJobOnAttempt($summary, 5);
    $queueJob->shouldNotReceive('release');

    expect($job->tries)->toBe(5);

    $job->handle();

    // Sent, addressed to a reader the email knows is entitled - which is what
    // keeps the paywall block out of it - and with no analysis.
    Mail::assertQueued(
        MonthlySummaryEmail::class,
        fn (MonthlySummaryEmail $mail): bool => $mail->analysis === null && $mail->pro,
    );

    expect($summary->fresh()->sent_at)->not->toBeNull()
        ->and(UserMailLog::where('user_id', $summary->user_id)
            ->where('email_type', DripEmailType::MonthlySummary)
            ->exists())->toBeTrue();

    // One event for a reader who really did lose the analysis.
    Exceptions::assertReportedCount(1);
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

    $rows = app(ReportPresenter::class)->present($summary->fresh(), 'en');
    $invested = collect($rows['rows'])->firstWhere('viz', 'bar');
    $widths = array_column(collect($rows['rows'])->pluck('data.segments')->flatten(1)->all(), 'width');

    expect($invested)->not->toBeNull()
        ->and(max($widths))->toBeLessThanOrEqual(100)
        ->and(min($widths))->toBeGreaterThanOrEqual(0);
});

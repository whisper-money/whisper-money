<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/*
 * The analysis is worth a few attempts: a provider hiccup costs a paying reader
 * their whole month. The retry only ever covered a provider that answered badly,
 * so one that did not answer at all - a 30s timeout reaching Gemini - burned the
 * month on the first try and was filed as a bug (PHP-LARAVEL-5Q).
 *
 * Spending the attempts is all this does. Whether an unreachable model is worth
 * holding the report back for belongs to the caller, so the failure is handed
 * back rather than filed here.
 */

beforeEach(function (): void {
    // Every case here exhausts the attempts, and the backoff between them grows.
    Sleep::fake();
});

function readerAwaitingAnalysis(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return [$summary, $user];
}

it('spends every attempt on a transient provider failure before handing it back', function (Closure $makeFailure): void {
    config()->set('ai_monthly_summary.attempts', 2);

    Exceptions::fake();
    Log::spy();

    $failure = $makeFailure();

    $calls = 0;
    MonthlySummaryAgent::fake(function () use (&$calls, $failure) {
        $calls++;

        throw $failure;
    });

    [$summary, $user] = readerAwaitingAnalysis();

    expect(fn (): ?string => app(AnalysisWriter::class)->draft($summary, $user))
        ->toThrow($failure::class);

    expect($calls)->toBe(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'Monthly summary analysis attempt failed.')
        ->twice();

    // Not filed here: the send holds the report and comes back, and one outage
    // would otherwise be one Sentry event per pass per reader.
    Exceptions::assertNothingReported();
})->with('transient provider failures');

it('recovers when a later attempt gets through', function (Closure $makeFailure): void {
    config()->set('ai_monthly_summary.attempts', 3);

    Exceptions::fake();

    $calls = 0;
    MonthlySummaryAgent::fake(function () use (&$calls, $makeFailure) {
        $calls++;

        if ($calls === 1) {
            throw $makeFailure();
        }

        return 'A steady month: you saved more than you spent.';
    });

    [$summary, $user] = readerAwaitingAnalysis();

    expect(app(AnalysisWriter::class)->draft($summary, $user))
        ->toBe('A steady month: you saved more than you spent.')
        ->and($calls)->toBe(2);

    Exceptions::assertNothingReported();
})->with('transient provider failures');

it('reports an unexpected failure once and does not burn the remaining attempts', function (): void {
    config()->set('ai_monthly_summary.attempts', 3);

    Exceptions::fake();

    $calls = 0;
    MonthlySummaryAgent::fake(function () use (&$calls) {
        $calls++;

        throw new RuntimeException('malformed response');
    });

    [$summary, $user] = readerAwaitingAnalysis();

    expect(app(AnalysisWriter::class)->draft($summary, $user))->toBeNull()
        ->and($calls)->toBe(1);

    Exceptions::assertReported(fn (RuntimeException $e): bool => $e->getMessage() === 'malformed response');
});

<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

/*
 * The analysis is worth a few attempts: a provider hiccup costs a paying reader
 * their whole month. The retry only ever covered a provider that answered badly,
 * so one that did not answer at all - a 30s timeout reaching Gemini - burned the
 * month on the first try and was filed as a bug (PHP-LARAVEL-5Q).
 */

function readerAwaitingAnalysis(): array
{
    $user = User::factory()->onboarded()->create(['currency_code' => 'EUR', 'locale' => 'en']);

    $summary = MonthlySummary::factory()->create([
        'user_id' => $user->id,
        'space_id' => $user->activeSpace()->id,
    ]);

    return [$summary, $user];
}

it('spends every attempt on a transient provider failure before giving up', function (Closure $makeFailure): void {
    config()->set('ai_monthly_summary.attempts', 2);

    Exceptions::fake();
    Log::spy();

    $calls = 0;
    MonthlySummaryAgent::fake(function () use (&$calls, $makeFailure) {
        $calls++;

        throw $makeFailure();
    });

    [$summary, $user] = readerAwaitingAnalysis();

    expect(app(AnalysisWriter::class)->draft($summary, $user))->toBeNull()
        ->and($calls)->toBe(2);

    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => $message === 'Monthly summary analysis attempt failed.')
        ->twice();

    // Quiet per attempt, but a run that never got through is an outage the logs
    // alone would bury.
    Exceptions::assertReportedCount(1);
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

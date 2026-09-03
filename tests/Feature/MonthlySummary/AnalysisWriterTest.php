<?php

use App\Ai\Agents\MonthlySummaryAgent;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
use Illuminate\Support\Facades\Exceptions;

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

it('spends its attempts on a transient provider failure instead of reporting it', function (Closure $makeFailure): void {
    config()->set('ai_monthly_summary.attempts', 2);

    Exceptions::fake();

    $calls = 0;
    MonthlySummaryAgent::fake(function () use (&$calls, $makeFailure) {
        $calls++;

        throw $makeFailure();
    });

    [$summary, $user] = readerAwaitingAnalysis();

    expect(app(AnalysisWriter::class)->draft($summary, $user))->toBeNull()
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

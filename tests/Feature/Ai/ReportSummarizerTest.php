<?php

use App\Ai\Agents\ReportSummaryAgent;
use App\Services\Ai\ReportSummarizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;

function summarize(bool $remember = true): ?string
{
    return app(ReportSummarizer::class)->summarize('test-report', 'Test report.', ['weeks' => [['week' => '2026-W26']]], $remember);
}

it('feeds the model the previous run figures and when they were captured', function () {
    ReportSummaryAgent::fake(['Los registros bajan.']);

    Cache::put('report_summary_baseline:test-report', [
        'captured_at' => now()->subWeek()->toIso8601String(),
        'payload' => ['weeks' => [['week' => '2026-W25']]],
    ], now()->addDay());

    expect(summarize())->toBe('Los registros bajan.');

    ReportSummaryAgent::assertPrompted(function ($prompt): bool {
        $payload = json_decode($prompt->prompt, true);

        return $payload['previous']['weeks'][0]['week'] === '2026-W25'
            && $payload['current']['weeks'][0]['week'] === '2026-W26'
            && str_starts_with($payload['previous_captured_at'], now()->subWeek()->toDateString());
    });
});

it('leaves the current figures as the baseline for the next run', function () {
    ReportSummaryAgent::fake(['Resumen.']);

    summarize();

    expect(Cache::get('report_summary_baseline:test-report'))
        ->toMatchArray(['payload' => ['weeks' => [['week' => '2026-W26']]]]);
});

it('keeps the stored baseline when the report is re-run the same day', function () {
    ReportSummaryAgent::fake(['Resumen.', 'Resumen.']);

    Cache::put('report_summary_baseline:test-report', [
        'captured_at' => now()->toIso8601String(),
        'payload' => ['weeks' => [['week' => 'last-week']]],
    ], now()->addDay());

    summarize();

    // A manual re-run must not overwrite the period the next scheduled run
    // compares against, otherwise the summary reports "no change".
    expect(Cache::get('report_summary_baseline:test-report')['payload'])
        ->toBe(['weeks' => [['week' => 'last-week']]]);
});

it('does not touch the baseline on a dry run', function () {
    ReportSummaryAgent::fake(['Resumen.']);

    summarize(remember: false);

    expect(Cache::get('report_summary_baseline:test-report'))->toBeNull();
});

it('strips backticks so the summary cannot swallow the table below it', function () {
    ReportSummaryAgent::fake(['Los pagos suben ```mucho```.']);

    expect(summarize())->toBe('Los pagos suben mucho.');
});

it('trims a runaway answer', function () {
    ReportSummaryAgent::fake([str_repeat('a', 2000)]);

    expect(mb_strlen((string) summarize()))->toBeLessThanOrEqual(900);
});

it('returns null for an empty answer', function () {
    ReportSummaryAgent::fake(['   ']);

    expect(summarize())->toBeNull();
});

it('returns null and reports the failure when the provider fails', function () {
    Exceptions::fake();
    ReportSummaryAgent::fake(fn () => throw new RuntimeException('provider down'));

    expect(summarize())->toBeNull();

    Exceptions::assertReported(fn (RuntimeException $exception): bool => $exception->getMessage() === 'provider down');
});

it('logs a transient provider failure without reporting it', function (Closure $makeFailure) {
    Exceptions::fake();
    Log::spy();
    ReportSummaryAgent::fake(fn () => throw $makeFailure());

    expect(summarize())->toBeNull();

    Exceptions::assertNothingReported();
    Log::shouldHaveReceived('warning')
        ->withArgs(fn (string $message): bool => str_contains($message, 'provider transient failure'))
        ->once();
})->with('transient provider failures');

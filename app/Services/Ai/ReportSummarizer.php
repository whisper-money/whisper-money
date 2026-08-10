<?php

namespace App\Services\Ai;

use App\Ai\Agents\ReportSummaryAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Throwable;

/**
 * Best-effort AI summary opening a scheduled stats report.
 *
 * The summary is a nice-to-have: a missing API key, a provider outage or a slow
 * response must never keep the report itself from being posted, so every failure
 * is logged and swallowed and the caller simply gets null.
 */
class ReportSummarizer
{
    /**
     * How long the previous run's figures are kept so the next run has a period
     * to compare against — comfortably longer than the monthly report's cadence.
     *
     * ponytail: the cache is enough for a directional summary; if a lost baseline
     * ever matters, persist the snapshots in a table instead.
     */
    private const BASELINE_DAYS = 70;

    /**
     * A Discord embed description is capped at 4096 characters and the report
     * table takes most of it, so a runaway answer is trimmed rather than risking
     * the whole payload being rejected.
     */
    private const MAX_LENGTH = 900;

    /**
     * @param  string  $report  identifies the report, so each keeps its own baseline
     * @param  string  $context  what the report measures and which period to compare
     * @param  array<string, mixed>  $payload  the figures the summary may talk about
     * @param  bool  $remember  keep this payload as the baseline for the next run
     */
    public function summarize(string $report, string $context, array $payload, bool $remember = true): ?string
    {
        $key = "report_summary_baseline:{$report}";
        $previous = Cache::get($key);

        if ($remember) {
            Cache::put($key, $payload, now()->addDays(self::BASELINE_DAYS));
        }

        try {
            $response = (new ReportSummaryAgent($context))->prompt(
                (string) json_encode(
                    ['current' => $payload, 'previous' => $previous],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
                ),
                provider: Lab::from((string) config('ai_reports.provider')),
                model: (string) config('ai_reports.model'),
                timeout: (int) config('ai_reports.timeout'),
            );
        } catch (Throwable $exception) {
            Log::warning('Report AI summary skipped: the provider call failed.', [
                'report' => $report,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        }

        $summary = trim($response->text);

        return $summary === '' ? null : Str::limit($summary, self::MAX_LENGTH);
    }
}

<?php

namespace App\Services\Ai;

use App\Ai\Agents\ReportSummaryAgent;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

/**
 * Best-effort AI summary opening a scheduled stats report.
 *
 * The summary is a nice-to-have: a missing API key, a provider outage or a slow
 * response must never keep the report itself from being posted, so every failure
 * is swallowed and the caller simply gets null.
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
     * Hard bound (ellipsis included) on the summary length: a Discord embed
     * description is capped and the report table takes most of it, so a runaway
     * answer is trimmed rather than eating the table's room.
     */
    private const MAX_SUMMARY_LENGTH = 900;

    /**
     * @param  string  $reportKey  identifies the report, so each keeps its own baseline
     * @param  string  $context  what the report measures and which period to compare
     * @param  array<string, mixed>  $payload  the figures the summary may talk about
     * @param  bool  $remember  keep this payload as the baseline for the next run
     */
    public function summarize(string $reportKey, string $context, array $payload, bool $remember = true): ?string
    {
        try {
            $previous = $this->baseline($reportKey, $payload, $remember);

            $response = (new ReportSummaryAgent($context))->prompt(
                (string) json_encode([
                    'current' => $payload,
                    'previous' => $previous['payload'] ?? null,
                    'previous_captured_at' => $previous['captured_at'] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                provider: Lab::from((string) config('ai_reports.provider')),
                model: (string) config('ai_reports.model'),
                timeout: (int) config('ai_reports.timeout'),
            );
        } catch (FailoverableException $exception) {
            // An overloaded or rate-limited provider is an expected transient
            // condition, not a bug, so it stays out of the error reports.
            Log::warning('Report AI summary skipped: provider transient failure.', [
                'report' => $reportKey,
                'exception' => $exception->getMessage(),
            ]);

            return null;
        } catch (Throwable $exception) {
            // Anything else (a misconfigured provider, an SDK change, a cache
            // outage) is a real bug: report it, but still let the report post.
            report($exception);

            return null;
        }

        // The summary is prepended to a fenced table, so a stray backtick from
        // the model would swallow the table into its own code block.
        $summary = trim(str_replace('`', '', $response->text));

        return $summary === '' ? null : Str::limit($summary, self::MAX_SUMMARY_LENGTH - 1, '…');
    }

    /**
     * Read the previous run's figures and, unless this is a dry run, leave the
     * current ones behind as the next run's baseline. A same-day re-run keeps
     * the stored baseline, so re-running a report by hand can't overwrite the
     * period the next scheduled run needs to compare against.
     *
     * @param  array<string, mixed>  $payload
     * @return array{captured_at: string, payload: array<string, mixed>}|null
     */
    private function baseline(string $reportKey, array $payload, bool $remember): ?array
    {
        $key = "report_summary_baseline:{$reportKey}";
        $previous = Cache::get($key);

        $capturedToday = isset($previous['captured_at'])
            && Str::startsWith($previous['captured_at'], now()->toDateString());

        if ($remember && ! $capturedToday) {
            Cache::put($key, [
                'captured_at' => now()->toIso8601String(),
                'payload' => $payload,
            ], now()->addDays(self::BASELINE_DAYS));
        }

        return is_array($previous) ? $previous : null;
    }
}

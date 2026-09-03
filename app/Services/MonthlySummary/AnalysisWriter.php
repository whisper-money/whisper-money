<?php

namespace App\Services\MonthlySummary;

use App\Ai\Agents\MonthlySummaryAgent;
use App\Enums\PlanFeature;
use App\Models\MonthlySummary;
use App\Models\User;
use App\Support\Figures;
use App\Support\Money;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\FailoverableException;
use Throwable;

/**
 * Produces the AI analysis for one frozen summary, once.
 *
 * Two gates, both required: a paid plan, and an active AI consent. Without the
 * consent nothing about the user reaches a model, whatever they pay — which is
 * also why a Pro user who never granted it sees the same locked block as a free
 * one rather than a broken section.
 *
 * The result is stored on the summary, so a send retried on the 4th does not pay
 * for a second generation, and the analysis a user read cannot change later.
 */
class AnalysisWriter
{
    /**
     * The language names the model is asked to write in, by app locale.
     */
    private const LANGUAGES = [
        'es' => 'Spanish (Spain)',
        'en' => 'English',
        'fr' => 'French',
    ];

    public function eligible(User $user): bool
    {
        return $user->canUseFeature(PlanFeature::AiSuggestions) && $user->hasActiveAiConsent();
    }

    /**
     * Write the analysis onto the summary, unless it already has one or the user
     * is not entitled to it. Returns the text, or null when there is none — the
     * report goes out either way.
     */
    public function write(MonthlySummary $summary, User $user): ?string
    {
        if ($summary->ai_analysis !== null) {
            return $summary->ai_analysis;
        }

        if (! $this->eligible($user)) {
            return null;
        }

        $analysis = $this->draft($summary, $user);

        if ($analysis === null) {
            return null;
        }

        $summary->forceFill(['ai_analysis' => $analysis, 'ai_generated_at' => now()])->save();

        return $analysis;
    }

    /**
     * A provider hiccup costs a paying user their analysis for a whole month, so
     * it is worth a few attempts. What it is never worth is delaying the report:
     * once the attempts are spent this returns null and the email goes without
     * the section.
     *
     * Nothing is written here: the caller decides whether the text is kept. That
     * is what lets the preview command show a real analysis without leaving one
     * on the summary the real send will read later in the month.
     */
    public function draft(MonthlySummary $summary, User $user): ?string
    {
        $attempts = max(1, (int) config('ai_monthly_summary.attempts'));
        $lastTransient = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->promptOnce($summary, $user);
            } catch (ConnectionException|FailoverableException $exception) {
                // Overloaded, rate-limited or simply not answering in time is
                // expected, not a bug. Back off and try again; only give up once
                // the attempts run out.
                $lastTransient = $exception;

                Log::warning('Monthly summary analysis attempt failed.', [
                    'summary_id' => $summary->id,
                    'attempt' => $attempt,
                    'exception' => $exception->getMessage(),
                ]);

                usleep($attempt * 500_000);
            } catch (Throwable $exception) {
                // A misconfigured provider or an SDK change is a real bug worth
                // reporting, and retrying it would only waste the window.
                report($exception);

                return null;
            }
        }

        // One hiccup is expected and stays in the logs. Every attempt failing is
        // an outage that just cost a paying reader their month, and a provider
        // that is never reachable would otherwise be invisible here.
        if ($lastTransient !== null) {
            report($lastTransient);
        }

        return null;
    }

    private function promptOnce(MonthlySummary $summary, User $user): ?string
    {
        $locale = $user->preferredLocale();

        $response = (new MonthlySummaryAgent(
            self::LANGUAGES[$locale] ?? self::LANGUAGES['en'],
            $summary->periodStart()->locale($locale)->isoFormat('MMMM YYYY'),
        ))->prompt(
            $this->payloadFor($summary, $locale),
            provider: Lab::from((string) config('ai_monthly_summary.provider')),
            model: (string) config('ai_monthly_summary.model'),
            timeout: (int) config('ai_monthly_summary.timeout'),
        );

        // The email renders the answer as paragraphs of plain text, so markdown
        // fences and backticks would show up verbatim.
        $text = trim(str_replace('`', '', $response->text));

        return $text === '' ? null : Str::limit($text, (int) config('ai_monthly_summary.max_length') - 1, '…');
    }

    /**
     * Every money figure in the payload, by path. The payload stores minor units
     * — 352000 is €3,520.00 — and a model has no way to know that: asked to
     * describe the month it will faithfully report a hundred times the amount.
     * So the amounts it receives are already formatted, and its instructions are
     * to copy them rather than compute anything.
     */
    private const MONEY_PATHS = [
        'net_worth.current', 'net_worth.previous', 'net_worth.diff',
        'net_worth.history.*.value',
        'cashflow.income', 'cashflow.expense', 'cashflow.net',
        'cashflow.previous.income', 'cashflow.previous.expense', 'cashflow.previous.net',
        'categories.total',
        'categories.top.*.amount', 'categories.top.*.previous_amount',
        'biggest_drop.amount', 'biggest_drop.previous_amount',
        'invested.contributed', 'invested.value', 'invested.gain',
        'budgets.overspent.*.over_by',
        'goal.saved', 'goal.target', 'goal.monthly_pace',
        'todos.uncategorised.amount',
    ];

    /**
     * The same for percentages, so the analysis writes them the way the rest of
     * the email does rather than as bare floats.
     */
    private const PERCENT_PATHS = [
        'net_worth.diff_percent', 'net_worth.year_percent',
        'cashflow.savings_rate', 'cashflow.previous.savings_rate', 'cashflow.expense_change_percent',
        'savings_rate_history.*.rate',
        'categories.top_share', 'categories.top.*.share', 'categories.top.*.change_percent',
        'biggest_drop.change_percent',
        'goal.percent',
    ];

    /**
     * The month's frozen figures plus the previous months already inside them,
     * with every amount and percentage rendered the way the reader will see them
     * elsewhere in the email. Nothing is added that is not already in the
     * payload, which is what keeps the promise printed under the block true.
     */
    private function payloadFor(MonthlySummary $summary, string $locale): string
    {
        $payload = $summary->payload;
        $currency = (string) ($payload['currency'] ?? 'EUR');

        foreach (self::MONEY_PATHS as $path) {
            $payload = $this->rewrite($payload, $path, fn (int|float $value): string => Money::formatIn((int) $value, $currency, $locale));
        }

        foreach (self::PERCENT_PATHS as $path) {
            $payload = $this->rewrite($payload, $path, fn (int|float $value): string => Figures::percent((float) $value, $locale));
        }

        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Rewrite one dotted path, wildcards included, leaving absent paths alone —
     * most of them are absent on any given month.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(int|float): string  $format
     * @return array<string, mixed>
     */
    private function rewrite(array $payload, string $path, callable $format): array
    {
        $found = data_get($payload, $path);

        // A wildcard path collects a list; a plain one returns the value itself.
        $values = str_contains($path, '*') ? (is_array($found) ? $found : []) : [$found];

        foreach ($values as $index => $value) {
            if (! is_int($value) && ! is_float($value)) {
                continue;
            }

            data_set(
                $payload,
                str_contains($path, '*') ? str_replace('*', (string) $index, $path) : $path,
                $format($value),
            );
        }

        return $payload;
    }
}

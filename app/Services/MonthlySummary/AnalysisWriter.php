<?php

namespace App\Services\MonthlySummary;

use App\Ai\Agents\MonthlySummaryAgent;
use App\Enums\PlanFeature;
use App\Models\MonthlySummary;
use App\Models\User;
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

        $analysis = $this->generate($summary, $user);

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
     */
    private function generate(MonthlySummary $summary, User $user): ?string
    {
        $attempts = max(1, (int) config('ai_monthly_summary.attempts'));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                return $this->promptOnce($summary, $user);
            } catch (FailoverableException $exception) {
                // Overloaded or rate-limited is expected, not a bug. Back off and
                // try again; only give up once the attempts run out.
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

        return null;
    }

    private function promptOnce(MonthlySummary $summary, User $user): ?string
    {
        $locale = $user->preferredLocale();

        $response = (new MonthlySummaryAgent(
            self::LANGUAGES[$locale] ?? self::LANGUAGES['en'],
            $summary->periodStart()->locale($locale)->isoFormat('MMMM YYYY'),
        ))->prompt(
            $this->payloadFor($summary),
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
     * The month's frozen figures plus the previous months already inside them.
     * Nothing is added here that is not already in the payload, which is what
     * keeps the promise printed under the block true.
     */
    private function payloadFor(MonthlySummary $summary): string
    {
        return (string) json_encode(
            $summary->payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\Drip\MonthlySummaryEmail;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\Summaries;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends every state of the monthly summary to one inbox, for looking at.
 *
 * The report has more states than a screenshot can show — with and without the
 * analysis, paying and not, a month that closed short, a first month with
 * nothing to compare against — and they only differ in ways you have to see in
 * a real client. This puts all of them in Mailhog in one go.
 *
 * It builds from a real account's real figures rather than a fixture, because
 * the whole point of looking is to catch what invented data hides: an empty
 * section, a sentence that reads wrong at three decimal places, a chart that
 * only looks right on a smooth ramp.
 *
 * Nothing here writes a mail log or marks a summary as sent: it bypasses the
 * job and hands the mailable straight to the mailer, so a preview can never
 * stop the real send going out later.
 */
class PreviewMonthlySummaryCommand extends Command
{
    protected $signature = 'email:monthly-summary-preview
        {email : Where to send the previews}
        {--source= : The account whose real figures to build from (defaults to the press account)}
        {--month= : The closed month to report, as YYYY-MM (defaults to last month)}';

    protected $description = 'Send every state of the monthly summary email to one inbox for review';

    /**
     * Stands in for the analysis when no model is reachable. Written by hand and
     * labelled as such, so nobody mistakes a preview for evidence that the
     * provider works.
     */
    private const SAMPLE_ANALYSIS = <<<'TEXT'
    [TEXTO DE MUESTRA] El 10,8 % de agosto no viene de ingresar más: los ingresos han quedado igual que en julio y lo que ha cambiado es que Alimentación subió 149 € y Transporte otros 64 €.

    Alimentación lleva tres meses subiendo y ya se lleva el 27,6 % de lo que gastas. Tu presupuesto Compra del mes se queda corto desde septiembre, y va a repetirse.

    Al ritmo de los últimos tres meses, tu objetivo llega a la meta en septiembre de 2027.
    TEXT;

    public function handle(Summaries $summaries): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $user = $this->sourceUser();

        if ($user === null) {
            $this->error('No source account found. Pass --source=<email>.');

            return self::FAILURE;
        }

        $month = $this->month();
        $summary = $summaries->freeze($user, $month, complete: true);

        if ($summary === null) {
            $this->error("{$user->email} has nothing to report for {$month->format('Y-m')}.");

            return self::FAILURE;
        }

        $this->info("Building from {$user->email}, {$month->format('Y-m')}.");

        foreach ($this->cases($user, $summary, $summaries, $month) as $label => $build) {
            $this->send($email, $user->preferredLocale(), $label, $build);
        }

        $this->newLine();
        $this->info('Open http://localhost:8025 to read them.');

        return self::SUCCESS;
    }

    /**
     * @return array<string, callable(): Mailable>
     */
    private function cases(User $user, MonthlySummary $summary, Summaries $summaries, Carbon $month): array
    {
        $cardUrl = $summaries->primaryCardUrl($summary, true);

        if ($cardUrl === null) {
            $this->warn('No card image: the renderer could not draw one, so the share block goes out without it.');
        }

        return [
            '1 · Pro, with the analysis' => fn () => $this->report($user, $summary, self::SAMPLE_ANALYSIS, $cardUrl, pro: true),
            '2 · Free, analysis locked' => fn () => $this->locked($this->freeReader($user), $summary, $cardUrl),
            '3 · Pro without AI consent' => fn () => $this->locked($user, $summary, $cardUrl),
            '4 · The month closed short' => fn () => $this->report($user, $this->incomplete($summary), self::SAMPLE_ANALYSIS, $cardUrl, pro: true),
            '5 · A first month' => fn () => $this->firstMonth($user, $summaries, $cardUrl),
            '6 · The 3rd-of-the-month reminder' => fn () => new MonthlySummaryReminderEmail(
                $user,
                $month,
                $month->copy()->addMonth()->startOfMonth()->addDays((int) config('monthly_summary.last_day') - 1),
            ),
        ];
    }

    private function report(User $user, MonthlySummary $summary, ?string $analysis, ?string $cardUrl, bool $pro): MonthlySummaryEmail
    {
        return new MonthlySummaryEmail($user, $summary, $analysis, $cardUrl, $pro);
    }

    /**
     * The locked block, in its two flavours. Which button it carries follows
     * `hasProPlan()`, so the free case is shown against an account that holds no
     * subscription rather than by switching billing off — the report is the same
     * either way, and only the button differs.
     */
    private function locked(User $reader, MonthlySummary $summary, ?string $cardUrl): MonthlySummaryEmail
    {
        return $this->report($reader, $summary, null, $cardUrl, pro: false);
    }

    /**
     * A local stand-in reader with no subscription, for the free-plan previews.
     */
    private function freeReader(User $source): User
    {
        return User::firstOrCreate(
            ['email' => 'preview-free@whisper.test'],
            ['name' => 'Preview (free plan)', 'password' => bcrypt(str()->random(32)), 'locale' => $source->locale],
        );
    }

    /**
     * The reader's earliest month with anything in it: a genuine first month
     * rather than a payload with its comparisons blanked out by hand.
     */
    private function firstMonth(User $user, Summaries $summaries, ?string $cardUrl): MonthlySummaryEmail
    {
        $earliest = Transaction::query()
            ->where('user_id', $user->id)
            ->min('transaction_date');

        $summary = $summaries->freeze($user, Carbon::parse($earliest)->startOfMonth(), complete: true);

        return $this->report($user, $summary ?? $summaries->find($user, Carbon::parse($earliest)), null, $cardUrl, pro: false);
    }

    /**
     * An unsaved copy flagged incomplete, so the preview cannot rewrite the
     * stored summary on its way past.
     */
    private function incomplete(MonthlySummary $summary): MonthlySummary
    {
        $copy = clone $summary;
        $copy->complete = false;

        return $copy;
    }

    /**
     * The locale has to be stated because a preview goes to a bare address
     * rather than to a User, and a string carries no `HasLocalePreference`.
     * Without it every preview arrives in English while the real send — which
     * addresses the model — arrives in the reader's own language.
     *
     * @param  callable(): Mailable  $build
     */
    private function send(string $email, string $locale, string $label, callable $build): void
    {
        try {
            Mail::to($email)->locale($locale)->sendNow($build());
            $this->line("  ✓ {$label}");
        } catch (Throwable $exception) {
            $this->error("  ✗ {$label}: ".$exception->getMessage());
        }
    }

    private function sourceUser(): ?User
    {
        $source = $this->option('source');

        if ($source !== null) {
            return User::query()->where('email', $source)->first();
        }

        return User::query()->where('email', config('app.press.email'))->first()
            ?? User::query()->whereNotNull('onboarded_at')->has('transactions')->first();
    }

    private function month(): Carbon
    {
        $month = $this->option('month');

        return $month === null
            ? now()->subMonth()->startOfMonth()
            : Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();
    }
}

<?php

namespace App\Console\Commands;

use App\Mail\Drip\MonthlySummaryEmail;
use App\Mail\Drip\MonthlySummaryReminderEmail;
use App\Models\MonthlySummary;
use App\Models\Transaction;
use App\Models\User;
use App\Services\MonthlySummary\AnalysisWriter;
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
 * a real client. This puts all of them in Mailhog in one go, or just the one
 * `--type` names.
 *
 * It builds from a real account's real figures rather than a fixture, because
 * the whole point of looking is to catch what invented data hides: an empty
 * section, a sentence that reads wrong at three decimal places, a chart that
 * only looks right on a smooth ramp.
 *
 * Nothing here writes a mail log or marks a summary as sent: it bypasses the
 * job and hands the mailable straight to the mailer, so a preview can never
 * stop the real send going out later. `--ai` keeps that promise too — the model
 * writes the analysis and nobody stores it — but it does send `--source`'s real
 * figures to the provider, past the consent gate the real send honours. Point it
 * at an account whose month you may show a model.
 */
class PreviewMonthlySummaryCommand extends Command
{
    protected $signature = 'email:monthly-summary-preview
        {email : Where to send the previews}
        {--source= : The account whose real figures to build from (defaults to the press account)}
        {--month= : The closed month to report, as YYYY-MM (defaults to last month)}
        {--type= : Send this case only: pro, free, pro-without-ai, short-closed-month, first-month, reminder}
        {--ai : Have the model write the analysis instead of using the sample text}';

    protected $description = 'Send every state of the monthly summary email to one inbox for review';

    /**
     * The cases, in the order they go out, keyed by the slug `--type` picks them
     * with. The values are what the console prints beside each one.
     */
    private const CASES = [
        'pro' => '1 · Pro, with the analysis',
        'free' => '2 · Free, analysis locked',
        'pro-without-ai' => '3 · Pro without AI consent',
        'short-closed-month' => '4 · The month closed short',
        'first-month' => '5 · A first month',
        'reminder' => '6 · The 3rd-of-the-month reminder',
    ];

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

    public function handle(Summaries $summaries, AnalysisWriter $writer): int
    {
        $email = (string) $this->argument('email');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        $type = $this->option('type');

        if ($type !== null && ! isset(self::CASES[$type])) {
            $this->error("Unknown type: {$type}. Pick one of: ".implode(', ', array_keys(self::CASES)).'.');

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

        $cases = $this->cases($user, $summary, $summaries, $month, $writer);

        if ($type !== null) {
            $cases = array_intersect_key($cases, [$type => null]);
        }

        foreach ($cases as $slug => $build) {
            $this->send($email, $user->preferredLocale(), self::CASES[$slug], $build);
        }

        $this->newLine();
        $this->info('Open http://localhost:8025 to read them.');

        return self::SUCCESS;
    }

    /**
     * Every case behind a callable, so the one `--type` names is the only one
     * that does any work: an unpicked case never freezes a month or asks a model
     * for anything.
     *
     * @return array<string, callable(): Mailable>
     */
    private function cases(User $user, MonthlySummary $summary, Summaries $summaries, Carbon $month, AnalysisWriter $writer): array
    {
        $cardUrl = $summaries->primaryCardUrl($summary, true);

        if ($cardUrl === null) {
            $this->warn('No card image: the renderer could not draw one, so the share block goes out without it.');
        }

        $analysis = $this->analysis($user, $summary, $writer);

        return [
            'pro' => fn () => $this->report($user, $summary, $analysis(), $cardUrl, pro: true),
            'free' => fn () => $this->locked($this->freeReader($user), $summary, $cardUrl),
            'pro-without-ai' => fn () => $this->locked($user, $summary, $cardUrl),
            'short-closed-month' => fn () => $this->report($user, $this->incomplete($summary), $analysis(), $cardUrl, pro: true),
            'first-month' => fn () => $this->firstMonth($user, $summaries, $cardUrl),
            'reminder' => fn () => new MonthlySummaryReminderEmail(
                $user,
                $month,
                $month->copy()->addMonth()->startOfMonth()->addDays((int) config('monthly_summary.last_day') - 1),
            ),
        ];
    }

    /**
     * The text the two reporting cases share. Behind a closure and remembered,
     * so `--ai` pays for one generation whichever of them go out, and for none
     * at all when `--type` picks a case that carries no analysis.
     *
     * It drafts rather than writes: `write()` would store the text on the real
     * summary, and the send later this month would hand the reader a preview's
     * analysis. Drafting also skips the plan and consent gates, which is the
     * point — the press account has neither, and the pro case is what we want to
     * look at.
     *
     * @return callable(): string
     */
    private function analysis(User $user, MonthlySummary $summary, AnalysisWriter $writer): callable
    {
        if (! $this->option('ai')) {
            return fn (): string => self::SAMPLE_ANALYSIS;
        }

        $drafted = false;
        $text = null;

        return function () use (&$drafted, &$text, $user, $summary, $writer): string {
            if (! $drafted) {
                $drafted = true;
                $text = $writer->draft($summary, $user);

                if ($text === null) {
                    $this->warn('The model wrote nothing: falling back to the sample text.');
                }
            }

            // An empty analysis block would read as the free case and mislead
            // whoever is reviewing, so the sample stands in for it.
            return $text ?? self::SAMPLE_ANALYSIS;
        };
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

<?php

namespace App\Console\Commands;

use App\Services\Ai\ReportSummarizer;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\SubscriptionFunnelCollector;
use Illuminate\Console\Command;

class SendSubscriptionFunnelReportCommand extends Command
{
    protected $signature = 'stats:subscription-funnel {--weeks= : Number of weekly cohorts to include} {--no-discord : Print the report to the console only, without posting to Discord}';

    protected $description = 'Post the weekly registration -> subscription -> paid funnel to Discord';

    /**
     * What the AI summary is looking at, and which periods it must compare.
     */
    private const SUMMARY_CONTEXT = <<<'CONTEXT'
    The report is a weekly signup -> subscription -> paid funnel: one row per
    signup-week cohort, every stage measured at the same cohort age. Each rate is
    null until its own horizon has elapsed: "subscribed_mature" governs
    subscribed_rate, "paid_mature" governs paid_rate and trial_to_paid_rate. The
    report is posted every Monday, so compare the most recent week that is mature
    for a given rate against the one before it, and put it in the context of the
    trend across the mature weeks.
    CONTEXT;

    public function __construct(
        private SubscriptionFunnelCollector $collector,
        private ReportSummarizer $summarizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('subscriptions.enabled')) {
            $this->info('Subscriptions are disabled; skipping the subscription funnel report.');

            return self::SUCCESS;
        }

        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;

        $report = $this->collector->collect($weeks);

        $summary = $this->summarizer->summarize(
            'subscription-funnel',
            self::SUMMARY_CONTEXT,
            $this->summaryPayload($report),
            remember: ! $this->option('no-discord'),
        );

        if ($summary !== null) {
            $this->line($summary);
            $this->newLine();
        }

        foreach ($this->tableLines($report) as $line) {
            $this->line($line);
        }

        if ($this->option('no-discord')) {
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report, $summary)]);

        $this->info('Subscription funnel report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{trialDays: int, weeks: list<array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $lines = [sprintf('%-9s %5s %5s %5s %5s %5s %5s', 'Semana', 'Reg', 'Sub', 'Sub%', 'Pago', 'Pag%', 'P/S')];

        foreach ($report['weeks'] as $row) {
            $lines[] = sprintf(
                '%-9s %5d %5d %5s %5d %5s %5s%s',
                $row['week'],
                $row['registered'],
                $row['subscribed'],
                $this->rateCell($row['subscribedRate'], $row['subscribedMature'], $row['registered']),
                $row['paid'],
                $this->rateCell($row['paidRate'], $row['paidMature'], $row['registered']),
                $this->rateCell($row['trialToPaidRate'], $row['paidMature'], $row['subscribed']),
                $row['surge'] ? ' ⚡' : '',
            );
        }

        return $lines;
    }

    /**
     * The figures the AI summary may talk about — the same ones the table shows.
     *
     * @param  array{trialDays: int, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function summaryPayload(array $report): array
    {
        return [
            'trial_days' => $report['trialDays'],
            'weeks' => array_map(fn (array $row): array => [
                'week' => $row['week'],
                'registered' => $row['registered'],
                'subscribed' => $row['subscribed'],
                'subscribed_rate' => $row['subscribedRate'],
                'paid' => $row['paid'],
                'paid_rate' => $row['paidRate'],
                'trial_to_paid_rate' => $row['trialToPaidRate'],
                'subscribed_mature' => $row['subscribedMature'],
                'paid_mature' => $row['paidMature'],
                'signup_surge' => $row['surge'],
            ], $report['weeks']),
        ];
    }

    /**
     * @param  array{trialDays: int, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report, ?string $summary): array
    {
        $mature = array_values(array_filter($report['weeks'], fn (array $row): bool => $row['paidMature']));

        $registered = array_sum(array_column($mature, 'registered'));
        $subscribed = array_sum(array_column($mature, 'subscribed'));
        $paid = array_sum(array_column($mature, 'paid'));

        $totals = $registered > 0
            ? sprintf(
                "Registrados  %d\nSuscritos    %d (%s%%)\nPagando      %d (%s%% de registros · %s%% de suscritos)",
                $registered,
                $subscribed,
                $this->pct($subscribed / $registered),
                $paid,
                $this->pct($paid / $registered),
                $subscribed > 0 ? $this->pct($paid / $subscribed) : '—',
            )
            : 'Aún no hay cohortes maduras.';

        $table = "```\n".implode("\n", $this->tableLines($report))."\n```";

        return [
            'title' => '💸 Embudo de suscripción — cohortes semanales',
            'description' => $summary !== null ? $summary."\n\n".$table : $table,
            'color' => 0x57F287,
            'fields' => [
                [
                    'name' => 'Cohortes maduras (referencia)',
                    'value' => "```\n".$totals."\n```",
                    'inline' => false,
                ],
                [
                    'name' => 'Leyenda',
                    'value' => 'Reg = registros · Sub = empezó un plan ≤30d después de registrarse · Pago = ese plan se cobró al pasar la prueba de '.$report['trialDays'].'d (activo, o cancelado solo después de cobrarse) · Sub%/Pag% sobre registros · P/S = pago ÷ suscritos · `pdte` = cohorte demasiado joven para puntuarla · ⚡ = pico de registros',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Solo orientativo',
                    'value' => 'Las cohortes se comparan a la misma edad. Las semanas con pico (⚡, p. ej. lanzamiento o marketing) llegan por otro canal de adquisición y no están controladas — compara semanas orgánicas entre sí. Esta es la referencia previa al A/B, no un test aleatorizado.',
                    'inline' => false,
                ],
            ],
        ];
    }

    private function rateCell(?float $rate, bool $mature, int $denominator): string
    {
        if ($denominator === 0) {
            return '—';
        }

        if (! $mature || $rate === null) {
            return 'pdte';
        }

        return $this->pct($rate).'%';
    }

    private function pct(float $rate): string
    {
        return (string) ((int) round($rate * 100));
    }
}

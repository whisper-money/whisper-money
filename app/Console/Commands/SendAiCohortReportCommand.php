<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersReportToConsole;
use App\Services\Ai\AiCohortReportCollector;
use App\Services\Ai\ReportSummarizer;
use App\Services\Discord\DiscordWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendAiCohortReportCommand extends Command
{
    use RendersReportToConsole;

    protected $signature = 'stats:ai-cohort-report {--weeks= : Number of weekly cohorts to include} {--no-discord : Print the report to the console only, without posting to Discord}';

    protected $description = 'Post the weekly AI-suggestions cohort retention/conversion report to Discord';

    /**
     * What the AI summary is looking at, and which periods it must compare.
     */
    private const SUMMARY_CONTEXT = <<<'CONTEXT'
    The report tracks the weekly cohorts of users eligible for the AI suggestions
    feature (retention, trial, paid and AI-consent rates): one row per signup
    week, every metric measured at the same cohort age, with "phase" telling
    whether the cohort signed up before or after the feature was released. Each
    rate is null until its own horizon has elapsed: "retention_mature" governs
    retained_rate and trial_rate, "paid_mature" governs paid_rate. The report is
    posted on the first day of each month, so compare the last four weeks against
    the four before them — month against previous month, not week against week.
    CONTEXT;

    public function __construct(
        private AiCohortReportCollector $collector,
        private ReportSummarizer $summarizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('subscriptions.enabled')) {
            $this->info('Subscriptions are disabled; skipping the AI cohort report.');

            return self::SUCCESS;
        }

        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;

        $report = $this->collector->collect($weeks);

        $summary = $this->summarizer->summarize(
            'ai-cohort-report',
            self::SUMMARY_CONTEXT,
            $this->summaryPayload($report),
            remember: ! $this->option('no-discord'),
        );

        $embed = $this->buildEmbed($report, $summary);

        if ($this->option('no-discord')) {
            $this->printEmbeds([$embed]);
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$embed]);

        $this->info('AI cohort report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * The figures the AI summary may talk about — the same ones the table shows.
     *
     * @param  array{releaseAt: ?CarbonImmutable, releaseWeek: ?string, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function summaryPayload(array $report): array
    {
        return [
            'release_week' => $report['releaseWeek'],
            'weeks' => array_map(fn (array $row): array => [
                'week' => $row['week'],
                'eligible' => $row['eligible'],
                'retained_rate' => $row['retainedRate'],
                'trial_rate' => $row['trialRate'],
                'paid_rate' => $row['paidRate'],
                'ai_accepted_rate' => $row['aiAcceptedRate'],
                'phase' => $row['phase'],
                'retention_mature' => $row['retentionMature'],
                'paid_mature' => $row['paidMature'],
                'signup_surge' => $row['surge'],
            ], $report['weeks']),
        ];
    }

    /**
     * @param  array{releaseAt: ?CarbonImmutable, releaseWeek: ?string, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report, ?string $summary): array
    {
        $lines = [sprintf('%-9s %5s %6s %6s %6s %5s', 'Semana', 'Eleg', 'Ret', 'Prueba', 'Pago', 'IA')];

        foreach ($report['weeks'] as $row) {
            $flags = '';

            if ($report['releaseWeek'] !== null && $row['week'] === $report['releaseWeek']) {
                $flags .= ' 🚀';
            }

            if ($row['surge']) {
                $flags .= ' ⚡';
            }

            $lines[] = sprintf(
                '%-9s %5d %6s %6s %6s %5s%s',
                $row['week'],
                $row['eligible'],
                $this->rateCell($row['retainedRate'], $row['retentionMature'], $row['eligible']),
                $this->rateCell($row['trialRate'], $row['retentionMature'], $row['eligible']),
                $this->rateCell($row['paidRate'], $row['paidMature'], $row['eligible']),
                $this->aiCell($row['aiAcceptedRate'], $row['eligible']),
                $flags,
            );
        }

        $release = $report['releaseAt'] !== null
            ? 'Primer consentimiento de IA (ancla de lanzamiento): '.$report['releaseAt']->copy()->locale('es')->translatedFormat('D, d M Y').' · semana '.$report['releaseWeek'].' 🚀'
            : 'Todavía no hay ningún consentimiento de IA — la función no está activa en producción.';

        $table = "```\n".implode("\n", $lines)."\n```";

        return [
            'title' => '🤖 Sugerencias con IA — informe de cohortes semanales',
            'description' => $summary !== null ? $summary."\n\n".$table : $table,
            'color' => 0x5865F2,
            'fields' => [
                [
                    'name' => 'Ancla de lanzamiento',
                    'value' => $release,
                    'inline' => false,
                ],
                [
                    'name' => 'Leyenda',
                    'value' => 'Eleg = usuarios con ≥50 transacciones en sus primeros 7 días · Ret = activos ≥14d después de registrarse · Prueba = se suscribieron ≤14d · Pago = suscripción activa ≤30d · IA = aceptaron el consentimiento de IA · `pdte` = cohorte demasiado joven para puntuarla · ⚡ = pico de registros',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Solo orientativo',
                    'value' => 'Es una comparación antes/después, no un test aleatorizado. Las cohortes se comparan a la misma edad. Las semanas con pico (⚡, p. ej. lanzamiento o YouTube) llegan por otro canal de adquisición y no están controladas — compara semanas orgánicas entre sí. La confianza se construye a lo largo de un trimestre, no en un solo mes.',
                    'inline' => false,
                ],
            ],
        ];
    }

    private function rateCell(?float $rate, bool $mature, int $eligible): string
    {
        if ($eligible === 0) {
            return '—';
        }

        if (! $mature || $rate === null) {
            return 'pdte';
        }

        return ((int) round($rate * 100)).'%';
    }

    private function aiCell(?float $rate, int $eligible): string
    {
        if ($eligible === 0 || $rate === null) {
            return '—';
        }

        return ((int) round($rate * 100)).'%';
    }
}

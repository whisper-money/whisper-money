<?php

namespace App\Console\Commands;

use App\Features\SubscriptionExperiment;
use App\Services\Ai\ReportSummarizer;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\BinomialProportion;
use App\Services\Stats\ExperimentFunnelCollector;
use App\Services\Stats\ProportionSignificance;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendExperimentFunnelReportCommand extends Command
{
    protected $signature = 'stats:experiment-funnel
        {--no-discord : Print the report to the console only, without posting to Discord}
        {--cost-per-connection=0.4 : Estimated cost (in the Cashier currency) per bank connection, used for the cost, burn and contribution-margin columns}';

    protected $description = 'Post the trial/pricing experiment funnel (per variant) to Discord';

    private const LABELS = [
        SubscriptionExperiment::CONTROL => 'control',
        SubscriptionExperiment::REDUCED_TRIAL => 'reduced',
        SubscriptionExperiment::PAY_NOW => 'pay_now',
    ];

    /**
     * What the AI summary is looking at, and which periods it must compare.
     */
    private const SUMMARY_CONTEXT = <<<'CONTEXT'
    The report is the A/B/C trial-and-pricing experiment: one row per variant
    (control, reduced, pay_now), cumulative since the experiment started. Conv%
    (conversions over matured users) and ARPU are the comparable metrics; the
    absolute MRR, cost and margin totals scale with how many users have matured,
    which differs per variant by design. Monetary amounts are in cents of the
    report currency, and null means there is no data yet rather than zero. The
    report is posted every Monday: compare against the previous run to say what
    moved this week, and never call a winner the significance block does not
    support.
    CONTEXT;

    public function __construct(
        private ExperimentFunnelCollector $collector,
        private ProportionSignificance $significance,
        private ReportSummarizer $summarizer,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('subscriptions.enabled')) {
            $this->info('Subscriptions are disabled; skipping the experiment funnel report.');

            return self::SUCCESS;
        }

        $costPerConnectionCents = (int) round(((float) $this->option('cost-per-connection')) * 100);
        $report = $this->collector->collect($costPerConnectionCents);

        if ($report['startedAt'] === null) {
            $this->warn('Experiment not started — set SUBSCRIPTION_EXPERIMENT_STARTED_AT to begin.');

            return self::SUCCESS;
        }

        $summary = $this->summarizer->summarize(
            'experiment-funnel',
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

        foreach ($this->significanceLines($report) as $line) {
            $this->line($line);
        }

        if ($this->option('no-discord')) {
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report, $summary)]);

        $this->info('Experiment funnel report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $revenue = $report['revenueAvailable'];
        $currency = $report['currency'];
        $lines = [sprintf(
            '%-8s %5s %5s %5s %5s %5s %6s %7s %7s %7s %7s %7s',
            'Variante', 'Asig', 'Actv', 'Tarj', 'UMad', 'Conv', 'Conv%', 'ARPU', 'MRR', 'Coste', 'Quema', 'MC',
        )];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['assignedMature'] > 0;
            $showMoney = $revenue && $mature;

            $lines[] = sprintf(
                '%-8s %5d %5d %5d %5d %5d %6s %7s %7s %7s %7s %7s',
                $label,
                $row['assigned'],
                $row['activated'],
                $row['subscribed'],
                $row['assignedMature'],
                $row['convertedMature'],
                $mature ? ((int) round($row['conversionRate'] * 100)).'%' : 'pdte',
                $showMoney && $row['arpuCents'] !== null ? Money::format($row['arpuCents'], $currency) : '—',
                $showMoney ? Money::format($row['mrrCents'], $currency) : '—',
                $mature ? Money::format($row['costCents'], $currency) : '—',
                $mature ? Money::format($row['wastedCostCents'], $currency) : '—',
                $showMoney ? Money::format($row['contributionMarginCents'], $currency) : '—',
            );
        }

        return $lines;
    }

    /**
     * Per-variant conversion-rate uncertainty (95% Wilson interval) plus the
     * leader-vs-runner-up verdict from {@see ProportionSignificance} — a Fisher
     * exact test and a Newcombe difference interval, Bonferroni-corrected — so
     * "check significance before calling a winner" has the numbers behind it.
     *
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function significanceLines(array $report): array
    {
        $lines = ['', 'Significancia (IC de Wilson al 95% sobre Conv%, n = UMad):'];
        $arms = [];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $n = (int) $row['assignedMature'];
            $k = (int) $row['convertedMature'];

            if ($n <= 0) {
                $lines[] = sprintf('  %-8s pdte (n=0)', $label);

                continue;
            }

            [$low, $high] = $this->significance->wilsonInterval($k, $n);
            $lines[] = sprintf('  %-8s %6s  [%6s – %6s]  (n=%d)', $label, $this->percent($k / $n), $this->percent($low), $this->percent($high), $n);
            $arms[] = new BinomialProportion($label, $k, $n);
        }

        if (count($arms) < 2) {
            $lines[] = 'Aún no hay suficientes variantes maduras para comparar.';

            return $lines;
        }

        usort($arms, fn (BinomialProportion $a, BinomialProportion $b): int => $b->rate() <=> $a->rate());
        [$leader, $runnerUp] = [$arms[0], $arms[1]];
        $result = $this->significance->compare($leader, $runnerUp);

        $lines[] = sprintf(
            'Líder %s vs %s: Δ %+.1f pts (IC 95%% %+.1f … %+.1f pts, Newcombe).',
            $leader->label, $runnerUp->label,
            ($leader->rate() - $runnerUp->rate()) * 100, $result['diffLow'] * 100, $result['diffHigh'] * 100,
        );
        $lines[] = sprintf(
            'Test exacto de Fisher p=%.3f %s α=%.3f (Bonferroni×3) -> %s.%s',
            $result['fisherP'], $result['significant'] ? '<' : '≥', $result['alpha'],
            $result['significant'] ? 'significativo' : 'no significativo',
            $result['significant'] ? '' : ' Hay que seguir midiendo.',
        );

        if ($result['minExpectedCount'] < 5.0) {
            $lines[] = sprintf(
                '(Muestra pequeña: el mínimo de conversiones esperadas es %.1f < 5, así que la aproximación normal z=%.2f sobreestima — se usa el test exacto.)',
                $result['minExpectedCount'], $result['z'],
            );
        }

        return $lines;
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }

    /**
     * The figures the AI summary may talk about — the same ones the table shows,
     * nulled under exactly the conditions that render them as "—", so the summary
     * can't claim a zero where the reader sees no data. The significance verdict
     * is handed over as the rendered lines, keeping one source of truth for it.
     *
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function summaryPayload(array $report): array
    {
        $variants = [];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['assignedMature'] > 0;
            $showMoney = $report['revenueAvailable'] && $mature;

            $variants[$label] = [
                'assigned' => $row['assigned'],
                'activated' => $row['activated'],
                'carded' => $row['subscribed'],
                'matured_users' => $row['assignedMature'],
                'converted_mature' => $row['convertedMature'],
                'conversion_rate' => $row['conversionRate'],
                'arpu_cents' => $showMoney ? $row['arpuCents'] : null,
                'mrr_cents' => $showMoney ? $row['mrrCents'] : null,
                'cost_cents' => $mature ? $row['costCents'] : null,
                'wasted_cost_cents' => $mature ? $row['wastedCostCents'] : null,
                'contribution_margin_cents' => $showMoney ? $row['contributionMarginCents'] : null,
            ];
        }

        return [
            'currency' => $report['currency'],
            'revenue_available' => $report['revenueAvailable'],
            'started_at' => $report['startedAt']?->toDateString(),
            'variants' => $variants,
            'significance' => array_values(array_filter($this->significanceLines($report))),
        ];
    }

    /**
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report, ?string $summary): array
    {
        $table = "```\n".implode("\n", $this->tableLines($report))."\n```";

        return [
            'title' => '🧪 Experimento de prueba/precio — embudo por variante',
            'description' => $summary !== null ? $summary."\n\n".$table : $table,
            'color' => 0xFEE75C,
            'fields' => [
                [
                    'name' => 'Inicio',
                    'value' => $report['startedAt']->copy()->locale('es')->translatedFormat('D, d M Y').' · los nuevos registros se reparten a partes iguales entre las tres variantes.',
                    'inline' => false,
                ],
                [
                    'name' => '📊 Significancia',
                    'value' => "```\n".implode("\n", $this->significanceLines($report))."\n```",
                    'inline' => false,
                ],
                [
                    // Keep this and "Cómo leerlo" tight: they are the only fields
                    // anywhere near Discord's 1024-character limit per field,
                    // beyond which DiscordWebhook has to trim them.
                    'name' => 'Leyenda',
                    'value' => sprintf(
                        'Asig = registros · Actv = activados (conectaron un banco o activaron la IA = coste disparado) · Tarj = checkout completado (tarjeta guardada) · UMad = asignados maduros (cohorte con edad para puntuarla en esta variante) · Conv = maduros que llegaron a convertir (se les cobró, menos devoluciones); no depende del momento, así que no baja porque una cohorte antigua haya tenido más tiempo para cancelar · Conv%% = Conv ÷ UMad · ARPU = MRR ÷ UMad · MRR = ritmo mensual de quienes pagan *ahora* (anuales ÷ 12); si Conv va por encima del MRR, es churn · Coste = coste estimado de conexiones de UMad (%s por conexión) · Quema = coste de conexión de maduros que nunca dejaron ingreso neto · MC = MRR − Coste · `pdte`/`—` = todavía sin datos maduros.',
                        Money::format($report['costPerConnectionCents'], $report['currency']),
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Cómo leerlo',
                    'value' => 'Cada variante madura con su propia ventana de decisión (control 15d, reduced 7d, pay_now 3d, +3d de liquidación), así que UMad difiere mucho entre variantes (pay_now madura antes). **Compara por Conv% y ARPU — normalizados por usuario maduro — y no por los totales de MRR/Coste/Quema/MC, que escalan con UMad y favorecen a la variante que más ha madurado.** Asig/Actv/Tarj son recuentos de toda la vida; de UMad hacia la derecha solo cuenta la cohorte madura, así que el embudo Actv→Tarj→Conv mezcla cohortes: léelo como volumen. Conv cuenta a quien se le cobró alguna vez, así que no se hunde en las cohortes antiguas como haría una foto de activos de hoy. El MC por usuario está por debajo del céntimo con este volumen: es contexto, no la decisión. Comprueba la significancia (n = UMad) antes de dar un ganador. El coste es una estimación plana por conexión, no la factura real de cada proveedor.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

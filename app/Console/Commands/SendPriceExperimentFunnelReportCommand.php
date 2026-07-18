<?php

namespace App\Console\Commands;

use App\Features\PriceExperiment;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\BinomialProportion;
use App\Services\Stats\PriceExperimentFunnelCollector;
use App\Services\Stats\ProportionSignificance;
use App\Services\Stats\WelchTTest;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendPriceExperimentFunnelReportCommand extends Command
{
    protected $signature = 'stats:price-experiment-funnel
        {--no-discord : Print the report to the console only, without posting to Discord}
        {--cost-per-connection=0.4 : Estimated monthly cost (in the Cashier currency) per active bank connection, used for the Cost/CM columns}';

    protected $description = 'Post the price experiment funnel (control vs high) to Discord — MONITORING ONLY, decide at the pre-registered horizon';

    private const LABELS = [
        PriceExperiment::CONTROL => 'control',
        PriceExperiment::HIGH => 'high',
    ];

    public function __construct(
        private PriceExperimentFunnelCollector $collector,
        private ProportionSignificance $significance,
        private WelchTTest $welch,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $costPerConnectionCents = (int) round(((float) $this->option('cost-per-connection')) * 100);
        $report = $this->collector->collect($costPerConnectionCents);

        if ($report['startedAt'] === null) {
            $this->warn('Price experiment not started — set PRICE_EXPERIMENT_STARTED_AT to begin.');

            return self::SUCCESS;
        }

        foreach ([...$this->tableLines($report), ...$this->significanceLines($report)] as $line) {
            $this->line($line);
        }

        if ($this->option('no-discord')) {
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report)]);

        $this->info('Price experiment funnel report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{currency: string, revenueAvailable: bool, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $currency = $report['currency'];
        $lines = [sprintf(
            '%-8s %5s %5s %5s %5s %5s %6s %8s %8s %8s %8s %6s',
            'Variant', 'Assg', 'Actd', 'Card', 'MatU', 'Conv', 'Conv%', 'MRR', 'Cost', 'CM', 'CM/u', 'C/Pyr',
        )];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['assignedMature'] > 0;
            $showMoney = $report['revenueAvailable'] && $mature;

            $lines[] = sprintf(
                '%-8s %5d %5d %5d %5d %5d %6s %8s %8s %8s %8s %6s',
                $label,
                $row['assigned'],
                $row['activated'],
                $row['subscribed'],
                $row['assignedMature'],
                $row['convertedMature'],
                $mature ? ((int) round($row['conversionRate'] * 100)).'%' : 'pend',
                $showMoney ? Money::format($row['mrrCents'], $currency) : '—',
                $mature ? Money::format($row['costCents'], $currency) : '—',
                $showMoney ? Money::format($row['contributionMarginCents'], $currency) : '—',
                $showMoney && $row['cmPerAssignedCents'] !== null ? Money::format($row['cmPerAssignedCents'], $currency) : '—',
                $row['connectionsPerPayer'] !== null ? number_format($row['connectionsPerPayer'], 1) : '—',
            );
        }

        return $lines;
    }

    /**
     * Primary decision test — Welch on contribution-margin-per-user (control vs
     * high), the metric we maximise — plus the conversion guardrail (Wilson CIs +
     * Fisher exact, a single control-vs-treatment contrast, so no multiplicity
     * correction). CM/user is the number to act on; conversion protects the funnel.
     *
     * @param  array{revenueAvailable: bool, currency: string, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function significanceLines(array $report): array
    {
        $currency = $report['currency'];
        $control = $report['variants'][PriceExperiment::CONTROL];
        $high = $report['variants'][PriceExperiment::HIGH];

        $lines = ['', 'PRIMARY — contribution margin per assigned user (Welch, high vs control):'];

        if (! $report['revenueAvailable'] || $control['assignedMature'] < 2 || $high['assignedMature'] < 2) {
            $lines[] = '  pend — need mature volume in both arms and Stripe revenue to compare.';
        } else {
            foreach (['control' => $control, 'high' => $high] as $label => $row) {
                $lines[] = sprintf('  %-8s %8s/user  (n=%d)', $label, Money::format((int) $row['cmPerAssignedCents'], $currency), $row['assignedMature']);
            }

            $welch = $this->welch->compare(
                $this->cmMean($high), $this->cmVariance($high), $high['assignedMature'],
                $this->cmMean($control), $this->cmVariance($control), $control['assignedMature'],
            );
            $significant = $welch['p'] < 0.05;
            $lines[] = sprintf(
                '  Δ high−control: %s/user  t=%.2f  p=%.3f %s α=0.05 -> %s.%s',
                Money::format((int) round($welch['diff']), $currency),
                $welch['t'], $welch['p'], $significant ? '<' : '≥',
                $significant ? 'significant' : 'not significant',
                $significant ? '' : ' Keep running.',
            );
        }

        $lines[] = '';
        $lines[] = 'GUARDRAIL — conversion (95% Wilson CI on Conv%, n = MatU):';
        $arms = [];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $n = (int) $row['assignedMature'];
            $k = (int) $row['convertedMature'];

            if ($n <= 0) {
                $lines[] = sprintf('  %-8s pend (n=0)', $label);

                continue;
            }

            [$lower, $upper] = $this->significance->wilsonInterval($k, $n);
            $lines[] = sprintf('  %-8s %6s  [%6s – %6s]  (n=%d)', $label, $this->percent($k / $n), $this->percent($lower), $this->percent($upper), $n);
            $arms[$key] = new BinomialProportion($label, $k, $n);
        }

        if (count($arms) === 2) {
            $result = $this->significance->compare($arms[PriceExperiment::HIGH], $arms[PriceExperiment::CONTROL], comparisons: 1);
            $lines[] = sprintf(
                '  Fisher exact p=%.3f %s α=%.3f -> %s.',
                $result['fisherP'], $result['significant'] ? '<' : '≥', $result['alpha'],
                $result['significant'] ? 'significant' : 'not significant',
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function cmMean(array $row): float
    {
        return $row['assignedMature'] > 0 ? (float) $row['contributionMarginCents'] / $row['assignedMature'] : 0.0;
    }

    /**
     * Sample variance (n−1) of per-user CM, from the accumulated moments.
     *
     * @param  array<string, mixed>  $row
     */
    private function cmVariance(array $row): float
    {
        $n = (int) $row['assignedMature'];

        if ($n < 2) {
            return 0.0;
        }

        $sum = (float) $row['contributionMarginCents'];

        return max(0.0, ((float) $row['cmSumSq'] - ($sum * $sum) / $n) / ($n - 1));
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }

    /**
     * @param  array{startedAt: CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, decisionWindowDays: int, variants: array<string, array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report): array
    {
        return [
            'title' => '💶 Price Experiment — Funnel (control vs high)',
            'description' => "```\n".implode("\n", [...$this->tableLines($report), ...$this->significanceLines($report)])."\n```",
            'color' => 0x57F287,
            'fields' => [
                [
                    'name' => 'Started',
                    'value' => $report['startedAt']->format('D, d M Y').sprintf(' · new signups split 50/50; both arms mature at %d days.', $report['decisionWindowDays']),
                    'inline' => false,
                ],
                [
                    'name' => 'Legend',
                    'value' => sprintf(
                        'Assg = signups · Actd = activated (bank or AI = cost triggered) · Card = started a subscription · MatU = matured assigned (old enough to score) · Conv%% = ever-charged net of refund ÷ MatU (time-invariant) · MRR = monthly run-rate of currently-paying subs · Cost = active connections × %s · CM = MRR − Cost · CM/u = CM ÷ MatU (the decision metric) · C/Pyr = active connections per payer.',
                        Money::format($report['costPerConnectionCents'], $report['currency']),
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ MONITORING ONLY',
                    'value' => "**Do NOT call a winner from this weekly report.** Decide at the pre-registered horizon N (from the power analysis), not the first week Fisher/Welch crosses α — weekly peeking inflates the false-positive rate. The primary metric is **CM per assigned user** (Welch); conversion is a guardrail. CM/user is sub-cent per week at current volume, so early weeks read as 'not significant' — that is expected. Don't add/remove arms or change the split mid-flight. A 1-month CM cannot see price-driven churn, so re-read retention/LTV before permanently locking the price.",
                    'inline' => false,
                ],
            ],
        ];
    }
}

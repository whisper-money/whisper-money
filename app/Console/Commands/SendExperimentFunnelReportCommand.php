<?php

namespace App\Console\Commands;

use App\Features\SubscriptionExperiment;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\ExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendExperimentFunnelReportCommand extends Command
{
    protected $signature = 'stats:experiment-funnel
        {--no-discord : Print the report to the console only, without posting to Discord}
        {--cost-per-connection=0.4 : Estimated cost (in the Cashier currency) per bank connection, used for the Cost/Burn/CM columns}';

    protected $description = 'Post the trial/pricing experiment funnel (per variant) to Discord';

    private const LABELS = [
        SubscriptionExperiment::CONTROL => 'control',
        SubscriptionExperiment::REDUCED_TRIAL => 'reduced',
        SubscriptionExperiment::PAY_NOW => 'pay_now',
    ];

    public function __construct(private ExperimentFunnelCollector $collector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $costPerConnectionCents = (int) round(((float) $this->option('cost-per-connection')) * 100);
        $report = $this->collector->collect($costPerConnectionCents);

        if ($report['startedAt'] === null) {
            $this->warn('Experiment not started — set SUBSCRIPTION_EXPERIMENT_STARTED_AT to begin.');

            return self::SUCCESS;
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

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report)]);

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
            'Variant', 'Assg', 'Actd', 'Card', 'MatU', 'Conv', 'Conv%', 'ARPU', 'MRR', 'Cost', 'Burn', 'CM',
        )];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['assignedMature'] > 0;
            $money = $revenue && $mature;

            $lines[] = sprintf(
                '%-8s %5d %5d %5d %5d %5d %6s %7s %7s %7s %7s %7s',
                $label,
                $row['assigned'],
                $row['activated'],
                $row['subscribed'],
                $row['assignedMature'],
                $row['convertedMature'],
                $mature ? ((int) round($row['conversionRate'] * 100)).'%' : 'pend',
                $money && $row['arpuCents'] !== null ? $this->money($row['arpuCents'], $currency) : '—',
                $money ? $this->money($row['mrrCents'], $currency) : '—',
                $mature ? $this->money($row['costCents'], $currency) : '—',
                $mature ? $this->money($row['wastedCostCents'], $currency) : '—',
                $money ? $this->money($row['contributionMarginCents'], $currency) : '—',
            );
        }

        return $lines;
    }

    /**
     * Conversion-rate uncertainty per variant (95% Wilson interval) plus a
     * two-proportion z-test between the two leaders, Bonferroni-corrected for
     * the three pairwise comparisons, so "check significance before calling a
     * winner" has the numbers behind it instead of just the warning.
     *
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function significanceLines(array $report): array
    {
        $z = 1.96; // two-sided 95%
        $lines = ['', 'Significance (95% Wilson CI on Conv%, n = MatU):'];
        $scored = [];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $n = (int) $row['assignedMature'];
            $k = (int) $row['convertedMature'];

            if ($n <= 0) {
                $lines[] = sprintf('  %-8s pend (n=0)', $label);

                continue;
            }

            [$low, $high] = $this->wilsonInterval($k, $n, $z);
            $lines[] = sprintf('  %-8s %6s  [%6s – %6s]  (n=%d)', $label, $this->percent($k / $n), $this->percent($low), $this->percent($high), $n);
            $scored[] = ['label' => $label, 'k' => $k, 'n' => $n, 'rate' => $k / $n];
        }

        if (count($scored) < 2) {
            $lines[] = 'Not enough matured variants to compare yet.';

            return $lines;
        }

        usort($scored, fn (array $a, array $b): int => $b['rate'] <=> $a['rate']);
        [$leader, $runnerUp] = [$scored[0], $scored[1]];

        if (min($leader['n'], $runnerUp['n']) < 30) {
            $lines[] = sprintf(
                'Leader %s (%s) vs %s (%s): samples still too small for a reliable test — keep running.',
                $leader['label'], $this->percent($leader['rate']), $runnerUp['label'], $this->percent($runnerUp['rate']),
            );

            return $lines;
        }

        $zStat = $this->twoProportionZ($leader['k'], $leader['n'], $runnerUp['k'], $runnerUp['n']);
        $threshold = 2.39; // Bonferroni: 0.05 / 3 pairwise comparisons, two-sided
        $significant = abs($zStat) >= $threshold;

        $lines[] = sprintf(
            'Leader %s vs %s: Δ %+.1f pts, z=%.2f — %s (need |z|≥%.2f, Bonferroni×3).%s',
            $leader['label'], $runnerUp['label'], ($leader['rate'] - $runnerUp['rate']) * 100, $zStat,
            $significant ? 'significant' : 'not significant', $threshold,
            $significant ? '' : ' Keep running.',
        );

        return $lines;
    }

    /**
     * Wilson score interval for a binomial proportion — accurate for small n
     * and near 0/1, where the normal approximation misbehaves.
     *
     * @return array{0: float, 1: float} lower and upper bound, clamped to [0, 1]
     */
    private function wilsonInterval(int $k, int $n, float $z): array
    {
        $p = $k / $n;
        $z2 = $z * $z;
        $denom = 1 + $z2 / $n;
        $center = ($p + $z2 / (2 * $n)) / $denom;
        $margin = ($z / $denom) * sqrt($p * (1 - $p) / $n + $z2 / (4 * $n * $n));

        return [max(0.0, $center - $margin), min(1.0, $center + $margin)];
    }

    private function twoProportionZ(int $kA, int $nA, int $kB, int $nB): float
    {
        $pooled = ($kA + $kB) / ($nA + $nB);
        $se = sqrt($pooled * (1 - $pooled) * (1 / $nA + 1 / $nB));

        return $se > 0.0 ? (($kA / $nA) - ($kB / $nB)) / $se : 0.0;
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }

    private function money(int $cents, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            default => $currency.' ',
        };

        return $symbol.number_format($cents / 100, 2);
    }

    /**
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, costPerConnectionCents: int, variants: array<string, array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report): array
    {
        return [
            'title' => '🧪 Trial/Pricing Experiment — Funnel by Variant',
            'description' => "```\n".implode("\n", $this->tableLines($report))."\n```",
            'color' => 0xFEE75C,
            'fields' => [
                [
                    'name' => 'Started',
                    'value' => $report['startedAt']->format('D, d M Y').' · new signups split evenly into the three variants.',
                    'inline' => false,
                ],
                [
                    'name' => '📊 Significance',
                    'value' => "```\n".implode("\n", $this->significanceLines($report))."\n```",
                    'inline' => false,
                ],
                [
                    'name' => 'Legend',
                    'value' => sprintf(
                        'Assg = signups · Actd = activated (connected a bank or enabled AI = cost triggered) · Card = completed checkout (card on file) · MatU = matured assigned (cohort old enough to score for this variant) · Conv = matured users who ever converted (were charged, net of refund) — time-invariant, so it does not shrink as an older cohort has longer to churn · Conv%% = Conv ÷ MatU (always ≤100%%, comparable across variants) · ARPU = MRR ÷ MatU (revenue per matured user) · MRR = monthly run-rate of *currently* paying subs (yearly ÷ 12); Conv above MRR is churn · Cost = est. connection cost of MatU (%s/connection) · Burn = connection cost of matured users who never earned net revenue (connected a bank but never paid, or paid then refunded) · CM = MRR − Cost · `pend`/`—` = no matured data yet.',
                        $this->money($report['costPerConnectionCents'], $report['currency']),
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ How to read it',
                    'value' => 'Each variant matures on its own decision window (control 15d, reduced 7d, pay_now 3d, +3d settle), so at any moment MatU differs a lot between variants (pay_now matures first). **Compare variants on Conv% and ARPU — normalized per matured user — not on the absolute MRR/Cost/Burn/CM totals, which scale with MatU and so mechanically favour whichever variant has matured more.** Assg/Actd/Card are lifetime counts; everything from MatU rightward covers the matured cohort only, so the raw Actd→Card→Conv funnel mixes cohorts (immature carded users can\'t have matured yet) — read it for volume. Conv counts anyone ever charged (net of refund), so it is not depressed for older cohorts the way a live-active snapshot would be. Per-user CM is sub-cent at current volume, so treat CM as directional context, not the decision. Check significance (sample size = MatU) before calling a winner. Cost is a flat per-connection estimate across all providers, not per-provider billing.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

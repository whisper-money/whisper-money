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

        // Decide with Fisher's exact test, not a normal-approximation z: at the
        // small conversion counts this report has, the pooled z overstates the
        // evidence (expected cell counts fall below the np>=5 the z-test needs).
        // Fisher is exact at any n. Bonferroni-correct for the 3 pairwise arms.
        $alpha = 0.05 / 3;
        $delta = ($leader['rate'] - $runnerUp['rate']) * 100;
        [$diffLow, $diffHigh] = $this->newcombeDiffInterval($leader['k'], $leader['n'], $runnerUp['k'], $runnerUp['n'], $z);
        $fisherP = $this->fisherExactTwoSided(
            $leader['k'], $leader['n'] - $leader['k'],
            $runnerUp['k'], $runnerUp['n'] - $runnerUp['k'],
        );
        $significant = $fisherP < $alpha;

        $lines[] = sprintf(
            'Leader %s vs %s: Δ %+.1f pts (95%% CI %+.1f … %+.1f pts, Newcombe).',
            $leader['label'], $runnerUp['label'], $delta, $diffLow * 100, $diffHigh * 100,
        );
        $lines[] = sprintf(
            'Fisher exact p=%.3f %s α=%.3f (Bonferroni×3) -> %s.%s',
            $fisherP, $significant ? '<' : '≥', $alpha,
            $significant ? 'significant' : 'not significant',
            $significant ? '' : ' Keep running.',
        );

        $pooled = ($leader['k'] + $runnerUp['k']) / ($leader['n'] + $runnerUp['n']);
        $minExpected = min($leader['n'], $runnerUp['n']) * min($pooled, 1 - $pooled);
        if ($minExpected < 5.0) {
            $lines[] = sprintf(
                '(Small sample: min expected conversions %.1f < 5, so the normal-approx z=%.2f overstates — exact test used.)',
                $minExpected, $this->twoProportionZ($leader['k'], $leader['n'], $runnerUp['k'], $runnerUp['n']),
            );
        }

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

    /**
     * Newcombe (Wilson-based) 95% interval for the difference of two proportions
     * pA − pB. The correct object when asking "is A better than B" — overlapping
     * marginal intervals do NOT imply the difference includes 0.
     *
     * @return array{0: float, 1: float}
     */
    private function newcombeDiffInterval(int $kA, int $nA, int $kB, int $nB, float $z): array
    {
        $pA = $kA / $nA;
        $pB = $kB / $nB;
        [$lA, $uA] = $this->wilsonInterval($kA, $nA, $z);
        [$lB, $uB] = $this->wilsonInterval($kB, $nB, $z);

        $lower = ($pA - $pB) - sqrt(($pA - $lA) ** 2 + ($uB - $pB) ** 2);
        $upper = ($pA - $pB) + sqrt(($uA - $pA) ** 2 + ($pB - $lB) ** 2);

        return [$lower, $upper];
    }

    /**
     * Two-sided Fisher exact test p-value for the 2x2 table [[a, b], [c, d]]
     * (a/c = conversions, b/d = non-conversions). Sums the hypergeometric
     * probabilities of every table, with the same margins, no more likely than
     * the observed one. Exact at any sample size — no normal approximation.
     */
    private function fisherExactTwoSided(int $a, int $b, int $c, int $d): float
    {
        $rowA = $a + $b;
        $rowB = $c + $d;
        $col = $a + $c;
        $total = $rowA + $rowB;

        if ($rowA === 0 || $rowB === 0 || $col === 0 || $col === $total) {
            return 1.0;
        }

        $logProbObserved = $this->hypergeometricLogProb($a, $rowA, $rowB, $col);
        $p = 0.0;
        for ($x = max(0, $col - $rowB); $x <= min($col, $rowA); $x++) {
            $logProb = $this->hypergeometricLogProb($x, $rowA, $rowB, $col);
            if ($logProb <= $logProbObserved + 1e-7) {
                $p += exp($logProb);
            }
        }

        return min(1.0, $p);
    }

    private function hypergeometricLogProb(int $x, int $rowA, int $rowB, int $col): float
    {
        return $this->logChoose($rowA, $x) + $this->logChoose($rowB, $col - $x) - $this->logChoose($rowA + $rowB, $col);
    }

    private function logChoose(int $n, int $k): float
    {
        if ($k < 0 || $k > $n) {
            return -INF;
        }

        return $this->logFactorial($n) - $this->logFactorial($k) - $this->logFactorial($n - $k);
    }

    private function logFactorial(int $n): float
    {
        $sum = 0.0;
        for ($i = 2; $i <= $n; $i++) {
            $sum += log($i);
        }

        return $sum;
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

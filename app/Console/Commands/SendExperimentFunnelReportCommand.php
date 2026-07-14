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
            'Variant', 'Assg', 'Actd', 'Card', 'MatU', 'Paid', 'Conv%', 'ARPU', 'MRR', 'Cost', 'Burn', 'CM',
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
                $row['activeMature'],
                $mature ? ((int) round($row['netActiveRate'] * 100)).'%' : 'pend',
                $money && $row['arpuCents'] !== null ? $this->money($row['arpuCents'], $currency) : '—',
                $money ? $this->money($row['mrrCents'], $currency) : '—',
                $mature ? $this->money($row['costCents'], $currency) : '—',
                $mature ? $this->money($row['wastedCostCents'], $currency) : '—',
                $money ? $this->money($row['contributionMarginCents'], $currency) : '—',
            );
        }

        return $lines;
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
                    'name' => 'Legend',
                    'value' => sprintf(
                        'Assg = signups · Actd = activated (connected a bank or enabled AI = cost triggered) · Card = completed checkout (card on file) · MatU = matured assigned (cohort old enough to score for this variant) · Paid = live non-refunded subs among MatU · Conv%% = Paid ÷ MatU (net conversion, always ≤100%%, comparable across variants) · ARPU = MRR ÷ MatU (revenue per matured user) · MRR = monthly run-rate of paid subs (yearly ÷ 12) · Cost = est. connection cost of MatU (%s/connection) · Burn = connection cost of matured users not currently paying · CM = MRR − Cost · `pend`/`—` = no matured data yet.',
                        $this->money($report['costPerConnectionCents'], $report['currency']),
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ How to read it',
                    'value' => 'Each variant matures on its own decision window (control 15d, reduced 7d, pay_now 3d, +3d settle), so at any moment MatU differs a lot between variants (pay_now matures first). **Compare variants on Conv% and ARPU — normalized per matured user — not on the absolute MRR/Cost/Burn/CM totals, which scale with MatU and so mechanically favour whichever variant has matured more.** Assg/Actd/Card are lifetime counts; everything from MatU rightward covers the matured cohort only, so the raw Actd→Card→Paid funnel mixes cohorts (immature carded users can\'t be Paid yet) — read it for volume. Per-user CM is sub-cent at current volume, so treat CM as directional context, not the decision. Check significance (sample size = MatU) before calling a winner. Cost is a flat per-connection estimate across all providers, not per-provider billing.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

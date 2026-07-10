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
        $lines = [sprintf('%-8s %5s %5s %5s %5s %6s %8s %8s %8s %8s', 'Variant', 'Assg', 'Actd', 'Card', 'Paid', 'A2P%', 'MRR', 'Cost', 'Burn', 'CM')];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['activatedMature'] > 0;

            $lines[] = sprintf(
                '%-8s %5d %5d %5d %5d %6s %8s %8s %8s %8s',
                $label,
                $row['assigned'],
                $row['activated'],
                $row['subscribed'],
                $row['activeMature'],
                $mature ? ((int) round($row['activationToPaidRate'] * 100)).'%' : 'pend',
                $revenue ? $this->money($row['mrrCents'], $currency) : '—',
                $mature ? $this->money($row['costCents'], $currency) : '—',
                $mature ? $this->money($row['wastedCostCents'], $currency) : '—',
                $revenue && $mature ? $this->money($row['contributionMarginCents'], $currency) : '—',
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
                        'Assg = signups · Actd = activated (connected a bank or enabled AI = cost triggered) · Card = completed checkout (card on file) · Paid = live non-refunded subs (mature) · A2P%% = Paid ÷ activated (mature) · MRR = monthly run-rate of paid subs (yearly ÷ 12) · Cost = est. connection cost of the mature cohort (%s/connection) · Burn = connection cost of mature users who never converted (money lost) · CM = MRR − Cost · `pend`/`—` = no mature data yet.',
                        $this->money($report['costPerConnectionCents'], $report['currency']),
                    ),
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ How to read it',
                    'value' => 'Each variant is gated by its own decision window (control 15d, reduced 7d, pay_now 3d), so pay_now matures first — compare only once all three have mature volume, and check significance before calling a winner. **CM (contribution margin) is the decision metric.** Assg/Actd/Card are lifetime counts; A2P%/Cost/Burn/CM cover only the mature cohort, so the raw Actd→Card→Paid funnel mixes cohorts (immature carded users can\'t be Paid yet) — read it for volume, compare variants on A2P%/CM. Burn is what the connect-and-leave leak costs. Cost is a flat per-connection estimate across all providers, not per-provider billing.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

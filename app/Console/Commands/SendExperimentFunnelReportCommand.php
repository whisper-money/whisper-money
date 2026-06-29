<?php

namespace App\Console\Commands;

use App\Features\SubscriptionExperiment;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\ExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendExperimentFunnelReportCommand extends Command
{
    protected $signature = 'stats:experiment-funnel {--no-discord : Print the report to the console only, without posting to Discord}';

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
        $report = $this->collector->collect();

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
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $revenue = $report['revenueAvailable'];
        $lines = [sprintf('%-8s %5s %4s %5s %5s %5s %5s %8s %8s', 'Variant', 'Assg', 'Sub', 'Actv', 'Cncl', 'Rfnd', 'Net%', 'MRR', 'ARPU')];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];
            $mature = $row['assignedMature'] > 0;

            $lines[] = sprintf(
                '%-8s %5d %4d %5d %5d %5d %5s %8s %8s',
                $label,
                $row['assigned'],
                $row['subscribed'],
                $row['active'],
                $row['canceled'],
                $row['refunded'],
                $mature ? ((int) round($row['netActiveRate'] * 100)).'%' : 'pend',
                $revenue ? $this->money($row['mrrCents'], $report['currency']) : '—',
                $revenue && $mature ? $this->money((int) $row['arpuCents'], $report['currency']) : '—',
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
     * @param  array{startedAt: ?CarbonImmutable, currency: string, revenueAvailable: bool, variants: array<string, array<string, mixed>>}  $report
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
                    'value' => 'Assg = assigned · Sub = started a plan · Actv/Cncl = current status · Rfnd = self-service refunds (pay_now) · Net% = live, non-refunded subs ÷ assigned (mature users only) · MRR = monthly run-rate of those subs (yearly ÷ 12) · ARPU = MRR ÷ assigned · `pend`/`—` = no mature data yet.',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ How to read it',
                    'value' => 'Each variant is gated by its own decision window (control 15d, reduced 7d, pay_now 3d), so pay_now matures first — compare only once all three have mature volume, and check significance before calling a winner. **ARPU is the revenue metric to compare.** MRR is run-rate, so it does not credit pay_now\'s yearly upfront cash; true LTV also needs a churn rate the experiment is too young to have.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

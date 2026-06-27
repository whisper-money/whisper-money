<?php

namespace App\Console\Commands;

use App\Features\SubscriptionExperiment;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\ExperimentFunnelCollector;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendExperimentFunnelReportCommand extends Command
{
    protected $signature = 'stats:experiment-funnel';

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

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report)]);

        $this->info('Experiment funnel report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{startedAt: ?CarbonImmutable, variants: array<string, array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $lines = [sprintf('%-8s %5s %4s %5s %5s %5s %5s %5s %5s', 'Variant', 'Assg', 'Sub', 'Trial', 'Actv', 'Cncl', 'PstD', 'Rfnd', 'Net%')];

        foreach (self::LABELS as $key => $label) {
            $row = $report['variants'][$key];

            $lines[] = sprintf(
                '%-8s %5d %4d %5d %5d %5d %5d %5d %5s',
                $label,
                $row['assigned'],
                $row['subscribed'],
                $row['trialing'],
                $row['active'],
                $row['canceled'],
                $row['pastDue'],
                $row['refunded'],
                $row['assignedMature'] === 0
                    ? 'pend'
                    : ((int) round($row['netActiveRate'] * 100)).'%',
            );
        }

        return $lines;
    }

    /**
     * @param  array{startedAt: ?CarbonImmutable, variants: array<string, array<string, mixed>>}  $report
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
                    'value' => 'Assg = assigned to the variant · Sub = started a plan · Trial/Actv/Cncl/PstD = current subscription status · Rfnd = self-service refunds (pay_now) · Net% = live, non-refunded subscriptions ÷ assigned, counting only users past their decision window · `pend` = no mature users yet.',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Read at equal age',
                    'value' => 'Net% gates each variant by its own decision window (control 15d, reduced 7d, pay_now 3d), so pay_now matures first. Compare Net% only once all three have meaningful mature volume.',
                    'inline' => false,
                ],
            ],
        ];
    }
}

<?php

namespace App\Console\Commands;

use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\SubscriptionFunnelCollector;
use Illuminate\Console\Command;

class SendSubscriptionFunnelReportCommand extends Command
{
    protected $signature = 'stats:subscription-funnel {--weeks= : Number of weekly cohorts to include}';

    protected $description = 'Post the weekly registration -> subscription -> paid funnel to Discord';

    public function __construct(private SubscriptionFunnelCollector $collector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;

        $report = $this->collector->collect($weeks);

        foreach ($this->tableLines($report) as $line) {
            $this->line($line);
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$this->buildEmbed($report)]);

        $this->info('Subscription funnel report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{trialDays: int, weeks: list<array<string, mixed>>}  $report
     * @return list<string>
     */
    private function tableLines(array $report): array
    {
        $lines = [sprintf('%-9s %5s %5s %5s %5s %5s %5s', 'Week', 'Reg', 'Sub', 'Sub%', 'Paid', 'Pd%', 'T2P')];

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
     * @param  array{trialDays: int, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report): array
    {
        $mature = array_values(array_filter($report['weeks'], fn (array $row): bool => $row['paidMature']));

        $registered = array_sum(array_column($mature, 'registered'));
        $subscribed = array_sum(array_column($mature, 'subscribed'));
        $paid = array_sum(array_column($mature, 'paid'));

        $totals = $registered > 0
            ? sprintf(
                "Registered  %d\nSubscribed  %d (%s%%)\nPaid        %d (%s%% of reg · %s%% of subs)",
                $registered,
                $subscribed,
                $this->pct($subscribed / $registered),
                $paid,
                $this->pct($paid / $registered),
                $subscribed > 0 ? $this->pct($paid / $subscribed) : '—',
            )
            : 'No mature cohorts yet.';

        return [
            'title' => '💸 Subscription Funnel — Weekly Cohorts',
            'description' => "```\n".implode("\n", $this->tableLines($report))."\n```",
            'color' => 0x57F287,
            'fields' => [
                [
                    'name' => 'Mature cohorts (baseline)',
                    'value' => "```\n".$totals."\n```",
                    'inline' => false,
                ],
                [
                    'name' => 'Legend',
                    'value' => 'Reg = signups · Sub = started a plan ≤30d after signup · Paid = that plan billed past the '.$report['trialDays'].'d trial (active, or canceled only after billing) · Sub%/Pd% of signups · T2P = paid ÷ subscribed · `pend` = cohort too young to score · ⚡ = signup surge',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Directional only',
                    'value' => 'Cohorts compared at equal age. Surge weeks (⚡, e.g. launch/marketing) differ in acquisition channel and are not controlled — compare organic weeks like-for-like. This is the pre-A/B baseline, not a randomised test.',
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
            return 'pend';
        }

        return $this->pct($rate).'%';
    }

    private function pct(float $rate): string
    {
        return (string) ((int) round($rate * 100));
    }
}

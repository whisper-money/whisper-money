<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersReportToConsole;
use App\Models\StuckCohortSnapshot;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stats\StuckCohortReportCollector;
use Illuminate\Console\Command;

class SendStuckCohortReportCommand extends Command
{
    use RendersReportToConsole;

    protected $signature = 'stats:stuck-cohort-report {--no-discord : Print the report to the console only, without posting to Discord}';

    protected $description = 'Post the weekly paywall stuck-cohort report (banked users without a valid subscription) to Discord';

    public function __construct(private StuckCohortReportCollector $collector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->collector->collect();
        $embed = $this->buildEmbed($report);

        if ($this->option('no-discord')) {
            $this->printEmbeds([$embed]);
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$embed]);

        $this->info('Stuck cohort report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{snapshot: StuckCohortSnapshot, previous: ?StuckCohortSnapshot, pctDelta: ?float, stuckDelta: ?int}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report): array
    {
        $snapshot = $report['snapshot'];

        $lines = [
            sprintf('Stuck       %d', $snapshot->stuck_count),
            sprintf('Onboarded   %d', $snapshot->onboarded_count),
            sprintf('Stuck rate  %s%%', $this->formatPct((float) $snapshot->stuck_pct)),
        ];

        if ($report['previous'] !== null) {
            $lines[] = '';
            $lines[] = sprintf(
                'vs %s: %s pp · %s stuck',
                $report['previous']->date->format('d M'),
                $this->formatSignedPct((float) $report['pctDelta']),
                $this->formatSignedInt((int) $report['stuckDelta']),
            );
        } else {
            $lines[] = '';
            $lines[] = 'First snapshot — no previous week to compare.';
        }

        return [
            'title' => '🪤 Paywall — Weekly Stuck Cohort',
            'description' => "```\n".implode("\n", $lines)."\n```",
            'color' => 0xED4245,
            'fields' => [
                [
                    'name' => 'Definition',
                    'value' => 'Stuck = onboarded users with a non-deleted banking connection but no valid subscription (active/trialing/past_due, or canceled but still within the grace period).',
                    'inline' => false,
                ],
                [
                    'name' => 'Denominator',
                    'value' => 'Stuck rate = stuck / onboarded users (`onboarded_at` not null) — the population that has reached the paywall.',
                    'inline' => false,
                ],
            ],
        ];
    }

    private function formatPct(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function formatSignedPct(float $value): string
    {
        $sign = $value > 0 ? '+' : '';

        return $sign.$this->formatPct($value);
    }

    private function formatSignedInt(int $value): string
    {
        return ($value > 0 ? '+' : '').$value;
    }
}

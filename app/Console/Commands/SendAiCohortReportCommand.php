<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersReportToConsole;
use App\Services\Ai\AiCohortReportCollector;
use App\Services\Discord\DiscordWebhook;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class SendAiCohortReportCommand extends Command
{
    use RendersReportToConsole;

    protected $signature = 'stats:ai-cohort-report {--weeks= : Number of weekly cohorts to include} {--no-discord : Print the report to the console only, without posting to Discord}';

    protected $description = 'Post the weekly AI-suggestions cohort retention/conversion report to Discord';

    public function __construct(private AiCohortReportCollector $collector)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $weeks = $this->option('weeks') !== null ? (int) $this->option('weeks') : null;

        $report = $this->collector->collect($weeks);
        $embed = $this->buildEmbed($report);

        if ($this->option('no-discord')) {
            $this->printEmbeds([$embed]);
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $webhookUrl = config('services.discord.ai_cohort_webhook_url')
            ?: config('services.discord.webhook_url');

        (new DiscordWebhook($webhookUrl))->send('', [$embed]);

        $this->info('AI cohort report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{releaseAt: ?CarbonImmutable, releaseWeek: ?string, weeks: list<array<string, mixed>>}  $report
     * @return array<string, mixed>
     */
    private function buildEmbed(array $report): array
    {
        $lines = [sprintf('%-9s %5s %6s %6s %6s %5s', 'Week', 'Elig', 'Ret', 'Trial', 'Paid', 'AI')];

        foreach ($report['weeks'] as $row) {
            $flags = '';

            if ($report['releaseWeek'] !== null && $row['week'] === $report['releaseWeek']) {
                $flags .= ' 🚀';
            }

            if ($row['surge']) {
                $flags .= ' ⚡';
            }

            $lines[] = sprintf(
                '%-9s %5d %6s %6s %6s %5s%s',
                $row['week'],
                $row['eligible'],
                $this->rateCell($row['retainedRate'], $row['retentionMature'], $row['eligible']),
                $this->rateCell($row['trialRate'], $row['retentionMature'], $row['eligible']),
                $this->rateCell($row['paidRate'], $row['paidMature'], $row['eligible']),
                $this->aiCell($row['aiAcceptedRate'], $row['eligible']),
                $flags,
            );
        }

        $release = $report['releaseAt'] !== null
            ? 'First AI consent (release anchor): '.$report['releaseAt']->format('D, d M Y').' · week '.$report['releaseWeek'].' 🚀'
            : 'No AI consent recorded yet — feature not live in production.';

        return [
            'title' => '🤖 AI Suggestions — Weekly Cohort Report',
            'description' => "```\n".implode("\n", $lines)."\n```",
            'color' => 0x5865F2,
            'fields' => [
                [
                    'name' => 'Release anchor',
                    'value' => $release,
                    'inline' => false,
                ],
                [
                    'name' => 'Legend',
                    'value' => 'Elig = users with ≥50 transactions in their first 7 days · Ret = active ≥14d after signup · Trial = subscribed ≤14d · Paid = active subscription ≤30d · AI = accepted AI consent · `pend` = cohort too young to score · ⚡ = signup surge',
                    'inline' => false,
                ],
                [
                    'name' => '⚠️ Directional only',
                    'value' => 'Pre/post comparison, not a randomised test. Cohorts are compared at equal age. Surge weeks (⚡, e.g. launch/YouTube) differ in acquisition channel and are not controlled — compare organic weeks like-for-like. Confidence builds over a quarter, not a single month.',
                    'inline' => false,
                ],
            ],
        ];
    }

    private function rateCell(?float $rate, bool $mature, int $eligible): string
    {
        if ($eligible === 0) {
            return '—';
        }

        if (! $mature || $rate === null) {
            return 'pend';
        }

        return ((int) round($rate * 100)).'%';
    }

    private function aiCell(?float $rate, int $eligible): string
    {
        if ($eligible === 0 || $rate === null) {
            return '—';
        }

        return ((int) round($rate * 100)).'%';
    }
}

<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RendersReportToConsole;
use App\Models\User;
use App\Services\Discord\DiscordWebhook;
use App\Services\Stripe\SubscriptionStatsCollector;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Stripe\Exception\ApiErrorException;

class SendDailyStatsReportCommand extends Command
{
    use RendersReportToConsole;

    protected $signature = 'stats:daily-report {--no-discord : Print the report to the console only, without posting to Discord}';

    protected $description = 'Post yesterday\'s user and Stripe subscription stats to the Discord admin channel';

    private const TIMEZONE = 'Europe/Madrid';

    public function __construct(
        private SubscriptionStatsCollector $collector,
        private DiscordWebhook $discord,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $stats = $this->collector->collect();
        } catch (ApiErrorException $exception) {
            $this->error("Stripe API error: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $yesterdayStart = Carbon::now(self::TIMEZONE)->subDay()->startOfDay();
        $todayStart = Carbon::now(self::TIMEZONE)->startOfDay();

        $newUsers = User::query()
            ->whereBetween('created_at', [$yesterdayStart->copy()->utc(), $todayStart->copy()->utc()])
            ->count();

        $totalUsers = User::query()
            ->where('created_at', '<', $todayStart->copy()->utc())
            ->count();

        $embed = $this->buildEmbed($stats, $newUsers, $totalUsers, $yesterdayStart);

        if ($this->option('no-discord')) {
            $this->printEmbeds([$embed]);
            $this->info('Skipped Discord (--no-discord).');

            return self::SUCCESS;
        }

        $this->discord->send('', [$embed]);

        $this->info('Daily stats report sent to Discord.');

        return self::SUCCESS;
    }

    /**
     * @param  array{active: array<string, array{count: int, mrr: float}>, trialing: array<string, array{count: int, mrr: float}>}  $stats
     * @return array<string, mixed>
     */
    private function buildEmbed(array $stats, int $newUsers, int $totalUsers, Carbon $day): array
    {
        $fields = [
            [
                'name' => '👥 Users',
                'value' => "New yesterday: **{$newUsers}**\nTotal: **{$totalUsers}**",
                'inline' => false,
            ],
        ];

        $currencies = array_unique(array_merge(array_keys($stats['active']), array_keys($stats['trialing'])));
        sort($currencies);

        foreach ($currencies as $currency) {
            $active = $stats['active'][$currency] ?? ['count' => 0, 'mrr' => 0.0];
            $trialing = $stats['trialing'][$currency] ?? ['count' => 0, 'mrr' => 0.0];

            $currentMrr = $active['mrr'];
            $projectedMrr = $active['mrr'] + $trialing['mrr'];

            $fields[] = [
                'name' => '💳 '.strtoupper($currency),
                'value' => implode("\n", [
                    "Active: **{$active['count']}** ({$this->money($currentMrr, $currency)} MRR)",
                    "Trialing: **{$trialing['count']}** ({$this->money($trialing['mrr'], $currency)} MRR)",
                    "Current MRR/ARR: **{$this->money($currentMrr, $currency)}** / **{$this->money($currentMrr * 12, $currency)}**",
                    "Projected MRR/ARR: **{$this->money($projectedMrr, $currency)}** / **{$this->money($projectedMrr * 12, $currency)}**",
                ]),
                'inline' => false,
            ];
        }

        if ($currencies === []) {
            $fields[] = [
                'name' => '💳 Subscriptions',
                'value' => 'No active or trialing subscriptions.',
                'inline' => false,
            ];
        }

        return [
            'title' => '📊 Daily Stats — '.$day->format('D, d M Y'),
            'color' => 0x5865F2,
            'fields' => $fields,
        ];
    }

    private function money(float $amount, string $currency): string
    {
        $symbol = match (strtolower($currency)) {
            'eur' => '€',
            'gbp' => '£',
            'usd' => '$',
            'jpy' => '¥',
            'brl' => 'R$',
            default => strtoupper($currency).' ',
        };

        return $symbol.number_format($amount, 2);
    }
}

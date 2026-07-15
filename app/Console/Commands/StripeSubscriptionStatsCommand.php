<?php

namespace App\Console\Commands;

use App\Services\Stripe\SubscriptionStatsCollector;
use App\Support\Money;
use Illuminate\Console\Command;
use Stripe\Exception\ApiErrorException;

class StripeSubscriptionStatsCommand extends Command
{
    protected $signature = 'stripe:subscription-stats';

    protected $description = 'Show Stripe subscription stats: active/trialing counts and current/projected MRR & ARR';

    /**
     * @var array<string, array{count: int, mrr: float}>
     */
    private array $active = [];

    /**
     * @var array<string, array{count: int, mrr: float}>
     */
    private array $trialing = [];

    public function __construct(private SubscriptionStatsCollector $collector)
    {
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

        $this->active = $stats['active'];
        $this->trialing = $stats['trialing'];

        $this->render();

        return self::SUCCESS;
    }

    private function render(): void
    {
        $currencies = array_unique(array_merge(array_keys($this->active), array_keys($this->trialing)));
        sort($currencies);

        if ($currencies === []) {
            $this->warn('No active or trialing subscriptions found.');

            return;
        }

        foreach ($currencies as $currency) {
            $active = $this->active[$currency] ?? ['count' => 0, 'mrr' => 0.0];
            $trialing = $this->trialing[$currency] ?? ['count' => 0, 'mrr' => 0.0];

            $currentMrr = $active['mrr'];
            $projectedMrr = $active['mrr'] + $trialing['mrr'];

            $this->newLine();
            $this->line('<options=bold>'.strtoupper($currency).'</>');
            $this->line("  Active subs:    <fg=green>{$active['count']}</> ({$this->format($active['mrr'], $currency)} MRR)");
            $this->line("  Trialing subs:  <fg=yellow>{$trialing['count']}</> ({$this->format($trialing['mrr'], $currency)} MRR)");
            $this->newLine();
            $this->line("  Current MRR:    <fg=cyan>{$this->format($currentMrr, $currency)}</>");
            $this->line("  Current ARR:    <fg=cyan>{$this->format($currentMrr * 12, $currency)}</>");
            $this->line("  Projected MRR:  <fg=cyan>{$this->format($projectedMrr, $currency)}</> (if trialing convert)");
            $this->line("  Projected ARR:  <fg=cyan>{$this->format($projectedMrr * 12, $currency)}</>");
        }

        $this->newLine();
    }

    /**
     * MRR figures are held in major units (e.g. euros), so convert to cents for
     * the shared formatter.
     */
    private function format(float $amount, string $currency): string
    {
        return Money::format((int) round($amount * 100), $currency);
    }
}

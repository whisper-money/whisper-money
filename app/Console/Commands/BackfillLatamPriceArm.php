<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\PriceTiers;
use App\Support\PricingRegion;
use App\Support\TimezoneCountry;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Places existing Latin American users on the regional `latam` price, the
 * way `CreateNewUser::priceArm()` does for new signups.
 *
 * Only users who are not paying are touched: anyone with a subscription that
 * has not ended (active, trialing, past due, cancelled on grace) or a generic
 * trial keeps the price they were offered, and a `price_arm` that is already
 * set is never overwritten. The shared demo and press accounts are skipped.
 * Run by hand once, after the regional price is switched on.
 */
class BackfillLatamPriceArm extends Command
{
    private const CHUNK_SIZE = 1000;

    protected $signature = 'pricing:backfill-latam-price-arm
        {--dry-run : Count the users who would be moved without changing anything}';

    protected $description = 'Set price_arm = latam on existing non-paying users detected in Latin America';

    public function handle(): int
    {
        $isDryRun = (bool) $this->option('dry-run');

        if (! $isDryRun && ! config('subscriptions.latam_pricing')) {
            $this->error('LATAM pricing is off (SUBSCRIPTION_LATAM_PRICING). Turn it on before backfilling, or pass --dry-run to preview.');

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->warn('DRY RUN — no changes will be saved.');
        }

        $breakdown = $this->backfill($isDryRun);
        $total = array_sum($breakdown);

        if ($total > 0) {
            arsort($breakdown);
            $this->table(['Region', 'Users'], collect($breakdown)->map(fn (int $count, string $region): array => [$region, $count])->values()->all());
        }

        $verb = $isDryRun ? 'would be moved' : 'moved';
        $this->info("{$total} user(s) {$verb} to the latam price.");

        return self::SUCCESS;
    }

    /**
     * Walks the eligible users in chunks and returns how many matched per
     * region: the timezone's country, or the currency when the timezone names
     * no country.
     *
     * @return array<string, int>
     */
    private function backfill(bool $isDryRun): array
    {
        $breakdown = [];

        $this->eligibleUsers()
            ->select(['id', 'timezone', 'currency_code'])
            ->chunkById(self::CHUNK_SIZE, function (Collection $users) use ($isDryRun, &$breakdown): void {
                $latamUsers = $users->filter(fn (User $user): bool => PricingRegion::isLatam($user->timezone, $user->currency_code));

                foreach ($latamUsers as $user) {
                    $region = $this->regionOf($user);
                    $breakdown[$region] = ($breakdown[$region] ?? 0) + 1;
                }

                if (! $isDryRun && $latamUsers->isNotEmpty()) {
                    User::query()
                        ->whereKey($latamUsers->modelKeys())
                        ->whereNull('price_arm')
                        ->update(['price_arm' => PriceTiers::LATAM]);
                }
            });

        return $breakdown;
    }

    /**
     * @return Builder<User>
     */
    private function eligibleUsers(): Builder
    {
        return User::query()
            ->excludingSharedAccounts()
            ->whereNull('price_arm')
            ->where(fn (Builder $query) => $query->whereNull('trial_ends_at')->orWhere('trial_ends_at', '<=', now()))
            ->whereDoesntHave('subscriptions', fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>', now()));
    }

    private function regionOf(User $user): string
    {
        return TimezoneCountry::of($user->timezone) ?? 'currency: '.strtoupper((string) $user->currency_code);
    }
}

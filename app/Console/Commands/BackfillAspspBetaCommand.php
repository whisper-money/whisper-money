<?php

namespace App\Console\Commands;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Stamps `aspsp_beta` on the EnableBanking connections that predate the column.
 *
 * The flag is denormalised onto the connection when the bank picker creates it,
 * so only rows created before that shipped are missing it. This asks the
 * provider's catalogue once per country rather than once per connection, and
 * leaves a row null when the catalogue no longer lists its bank — an unknown
 * flag renders as no badge, which is the honest outcome.
 *
 * Deliberately a command and not part of the migration: a migration that calls
 * an external API fails the deploy when the API is down.
 *
 * A country whose catalogue will not load stops the run. Countries already
 * written stay written - each row is stamped on its own and the query only
 * picks up rows that are still null, so a rerun continues where this left off
 * rather than redoing it. That is preferred over holding a transaction open
 * across an HTTP call per country.
 */
class BackfillAspspBetaCommand extends Command
{
    protected $signature = 'banking:backfill-aspsp-beta
        {--dry-run : Report what would change without writing}';

    protected $description = 'Stamp the provider beta flag onto EnableBanking connections created before it was stored';

    public function handle(BankingProviderInterface $provider): int
    {
        $connections = BankingConnection::query()
            ->withTrashed()
            ->where('provider', BankingProvider::EnableBanking)
            ->whereNull('aspsp_beta')
            ->whereNotNull('aspsp_country')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('Nothing to backfill.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $unknown = [];
        $updated = 0;
        $beta = 0;

        foreach ($connections->groupBy('aspsp_country') as $country => $forCountry) {
            $flags = $this->betaFlagsFor($provider, (string) $country);

            if ($flags === null) {
                return self::FAILURE;
            }

            foreach ($forCountry as $connection) {
                if (! array_key_exists($connection->aspsp_name, $flags)) {
                    $unknown[] = "{$connection->aspsp_name} ({$country})";

                    continue;
                }

                $isBeta = $flags[$connection->aspsp_name];

                if (! $dryRun) {
                    $connection->update(['aspsp_beta' => $isBeta]);
                }

                $updated++;
                $beta += $isBeta ? 1 : 0;
            }
        }

        $this->info(sprintf(
            '%s %d connection(s); %d on a beta connector.',
            $dryRun ? 'Would stamp' : 'Stamped',
            $updated,
            $beta,
        ));

        if ($unknown !== []) {
            // One per line: on the real catalogue this list runs to over a
            // hundred banks, and a single comma-joined line is unreadable.
            $unique = array_unique($unknown);

            $this->warn(sprintf('%d left unstamped, no longer in the catalogue:', count($unique)));

            foreach ($unique as $bank) {
                $this->line("  {$bank}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Bank name to beta flag for one country, or null when the provider failed.
     *
     * @return array<string, bool>|null
     */
    private function betaFlagsFor(BankingProviderInterface $provider, string $country): ?array
    {
        try {
            $institutions = $provider->getInstitutions($country);
        } catch (\Throwable $e) {
            $this->error("Could not fetch the {$country} catalogue: {$e->getMessage()}");

            return null;
        }

        // A country can list the same bank once per authorisation method; beta
        // is a property of the bank as the user sees it, so one beta entry is
        // enough to call the bank beta.
        return collect($institutions)
            ->groupBy('name')
            ->map(fn (Collection $entries): bool => $entries->contains(fn (array $institution): bool => $institution['beta']))
            ->all();
    }
}

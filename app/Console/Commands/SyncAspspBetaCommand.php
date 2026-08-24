<?php

namespace App\Console\Commands;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingProvider;
use App\Models\BankingConnection;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reconciles `aspsp_beta` on every EnableBanking connection with the provider's
 * current catalogue.
 *
 * The flag is denormalised onto the connection when the bank picker creates it,
 * because the connections screen renders stored rows and never sees the
 * catalogue again. That leaves it frozen at whatever the bank was on the day
 * the user connected, and the provider moves connectors in both directions: one
 * that stabilises loses the flag, one that regresses gains it. So this runs on a
 * schedule rather than once, and writes both ways.
 *
 * It asks the catalogue once per country, not once per connection, and only
 * writes rows whose answer actually changed. Soft-deleted connections are left
 * out: nothing renders their badge, so refreshing it is work for no one.
 *
 * A bank the catalogue no longer lists keeps whatever we last knew. Clearing it
 * to null would trade a stale answer for no answer, and no answer renders no
 * badge - a bank silently losing its beta warning is the worse of the two.
 *
 * Deliberately a command and not migration logic: a migration that calls an
 * external API fails the deploy when the API is down. Nothing here is
 * order-dependent or partial, so a run cut short is simply redone next time.
 */
class SyncAspspBetaCommand extends Command
{
    protected $signature = 'banking:sync-aspsp-beta
        {--dry-run : Report what would change without writing}';

    protected $description = 'Reconcile the stored beta flag on EnableBanking connections with the provider catalogue';

    private bool $dryRun = false;

    private int $checked = 0;

    private int $onBeta = 0;

    private int $stamped = 0;

    private int $enteredBeta = 0;

    private int $leftBeta = 0;

    /** @var list<string> */
    private array $unknown = [];

    /** @var list<string> */
    private array $failedCountries = [];

    public function handle(BankingProviderInterface $provider): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        $connections = BankingConnection::query()
            ->where('provider', BankingProvider::EnableBanking)
            ->whereNotNull('aspsp_country')
            ->get();

        if ($connections->isEmpty()) {
            $this->info('No EnableBanking connections to check.');

            return self::SUCCESS;
        }

        foreach ($connections->groupBy('aspsp_country') as $country => $forCountry) {
            $flags = $this->betaFlagsFor($provider, (string) $country);

            // One country's catalogue being down says nothing about the others,
            // and this runs unattended - so carry on and fail at the end.
            if ($flags === null) {
                $this->failedCountries[] = (string) $country;

                continue;
            }

            $this->reconcile($forCountry, $flags, (string) $country);
        }

        return $this->report();
    }

    /**
     * @param  EloquentCollection<int, BankingConnection>  $connections
     * @param  array<string, bool>  $flags
     */
    private function reconcile(EloquentCollection $connections, array $flags, string $country): void
    {
        foreach ($connections as $connection) {
            if (! array_key_exists((string) $connection->aspsp_name, $flags)) {
                $this->unknown[] = "{$connection->aspsp_name} ({$country})";

                continue;
            }

            $isBeta = $flags[(string) $connection->aspsp_name];

            $this->checked++;
            $this->onBeta += $isBeta ? 1 : 0;

            $previous = $connection->aspsp_beta;

            if ($previous === $isBeta) {
                continue;
            }

            if ($previous === null) {
                $this->stamped++;
            } elseif ($isBeta) {
                $this->enteredBeta++;
            } else {
                $this->leftBeta++;
            }

            if (! $this->dryRun) {
                $connection->update(['aspsp_beta' => $isBeta]);
            }
        }
    }

    private function report(): int
    {
        $this->info(sprintf('Checked %d connection(s); %d on a beta connector.', $this->checked, $this->onBeta));
        $this->info(sprintf(
            '%s %d stamped for the first time, %d entered beta, %d left beta.',
            $this->dryRun ? 'Would change:' : 'Changed:',
            $this->stamped,
            $this->enteredBeta,
            $this->leftBeta,
        ));

        if ($this->unknown !== []) {
            // One per line: on the real catalogue this list runs to over a
            // hundred banks, and a single comma-joined line is unreadable.
            $unique = array_unique($this->unknown);

            $this->warn(sprintf('%d kept their stored flag, no longer in the catalogue:', count($unique)));
            $this->line('  '.implode(PHP_EOL.'  ', $unique));
        }

        if ($this->failedCountries !== []) {
            $this->error('Could not check: '.implode(', ', $this->failedCountries).'.');

            return self::FAILURE;
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
        } catch (Throwable $e) {
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

<?php

namespace App\Console\Commands;

use App\Contracts\BankingProviderInterface;
use App\Support\Marketing\IntegrationsPage;
use App\Support\Marketing\MarketingContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Refreshes the bank catalogue the public integrations page renders.
 *
 * It writes its own file and touches neither database/seeders/data/banks.json
 * (hand-maintained, no country field, logos from a previous provider) nor the
 * `banks` table (rows created when a user connects, so it describes what people
 * connected, not what is available). The only source is the open banking
 * provider's own catalogue, asked once per country we offer.
 *
 * Re-run it when the provider adds or drops institutions, and commit the result.
 */
class SyncBankInstitutionsCommand extends Command
{
    protected $signature = 'banks:sync-institutions
        {--path= : Write somewhere other than the committed catalogue, relative to the project root}
        {--force : Write even when a country loses most of its banks}';

    protected $description = 'Refresh the committed bank catalogue for the public integrations page';

    /**
     * Below this, a country is too small for a drop to mean anything.
     */
    private const COLLAPSE_FLOOR = 5;

    public function handle(BankingProviderInterface $provider): int
    {
        $countries = [];

        foreach (array_keys(MarketingContent::COUNTRIES) as $country) {
            // A country's own response can name the same institution twice, once
            // per authorisation method it supports; the page lists banks, not
            // authorisation methods.
            $banks = collect($provider->getInstitutions($country))
                ->pluck('name')
                ->filter()
                ->reject(fn (string $name): bool => str_contains($name, IntegrationsPage::TEST_INSTITUTION))
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            $countries[$country] = $banks;

            $this->line(sprintf('%s: %d', $country, count($banks)));
        }

        $collapsed = $this->collapsedCountries($countries);

        if ($collapsed !== [] && ! $this->option('force')) {
            $this->error('Refusing to write: '.implode(', ', $collapsed).'.');
            $this->line('This is what a restricted provider app looks like — check the credentials, or pass --force if the loss is real.');

            return self::FAILURE;
        }

        $relativePath = (string) ($this->option('path') ?: IntegrationsPage::CATALOGUE);

        File::ensureDirectoryExists(dirname(base_path($relativePath)));

        File::put(base_path($relativePath), json_encode([
            'generated_by' => 'php artisan banks:sync-institutions',
            'generated_at' => now()->toDateString(),
            'source' => 'EnableBanking GET /aspsps?country=XX&psu_type=personal',
            'countries' => $countries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf('Wrote %d banks across %d countries to %s.', array_sum(array_map('count', $countries)), count($countries), $relativePath));

        return self::SUCCESS;
    }

    /**
     * Countries that just lost more than half their banks against the committed
     * file. A restricted provider app returns a near-empty catalogue rather than
     * an error, which is how a page promising a bank list ends up promising two
     * banks; that has happened, so it is worth refusing to overwrite.
     *
     * @param  array<string, list<string>>  $countries
     * @return list<string>
     */
    private function collapsedCountries(array $countries): array
    {
        $collapsed = [];

        foreach (IntegrationsPage::committedCounts() as $code => $before) {
            $after = count($countries[$code] ?? []);

            if ($before >= self::COLLAPSE_FLOOR && $after < $before / 2) {
                $collapsed[] = sprintf('%s went from %d banks to %d', $code, $before, $after);
            }
        }

        return $collapsed;
    }
}

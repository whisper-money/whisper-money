<?php

namespace App\Console\Commands;

use App\Contracts\BankingProviderInterface;
use App\Support\Marketing\IntegrationsPage;
use App\Support\Marketing\MarketingContent;
use Illuminate\Console\Command;

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
    protected $signature = 'banks:sync-institutions {--path= : Write somewhere other than the committed catalogue}';

    protected $description = 'Refresh the committed bank catalogue for the public integrations page';

    public function handle(BankingProviderInterface $provider): int
    {
        $countries = [];

        foreach (array_keys(MarketingContent::COUNTRIES) as $country) {
            // A country's own response can name the same institution twice, once
            // per authorisation method it supports; the page lists banks, not
            // authorisation methods. The provider's own test institution is not
            // a bank anyone can connect, so it never reaches a public page.
            $banks = collect($provider->getInstitutions($country))
                ->pluck('name')
                ->filter()
                ->reject(fn (string $name): bool => str_contains($name, 'Mock ASPSP'))
                ->unique()
                ->sort(SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();

            $countries[$country] = $banks;

            $this->line(sprintf('%s: %d', $country, count($banks)));
        }

        $relativePath = (string) ($this->option('path') ?: IntegrationsPage::CATALOGUE);
        $path = base_path($relativePath);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, json_encode([
            'generated_by' => 'php artisan banks:sync-institutions',
            'generated_at' => now()->toDateString(),
            'source' => 'EnableBanking GET /aspsps?country=XX&psu_type=personal',
            'countries' => $countries,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n");

        $this->info(sprintf('Wrote %d banks across %d countries to %s.', array_sum(array_map('count', $countries)), count($countries), $relativePath));

        return self::SUCCESS;
    }
}

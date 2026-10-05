<?php

namespace App\Services\Imports;

use App\Enums\ImportAccountAction;
use App\Models\Account;
use App\Models\Import;
use App\Services\AccountWriteService;
use App\Services\CurrencyOptions;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turns the accounts plan of a full import into real accounts: creates the new
 * ones, resolves the ones mapped onto an account the user already has, and
 * points merged and skipped ones where their rows belong.
 *
 * Each plan entry is what StoreFullImportRequest validated: a `key`, an
 * `action`, and the fields that action needs.
 */
class ImportAccountWriter
{
    public function __construct(
        private AccountWriteService $accountWriter,
        private CurrencyOptions $currencyOptions,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    public function write(Import $import, array $plan): ImportAccountMap
    {
        $map = new ImportAccountMap;
        $entries = collect($plan);

        foreach ($this->inCreationOrder($import, $entries->where('action', ImportAccountAction::Create->value)) as $entry) {
            $map->assign($entry['key'], $this->create($import, $entry), ownsBalances: true, preexisting: false);
        }

        foreach ($entries->where('action', ImportAccountAction::Map->value) as $entry) {
            $map->assign($entry['key'], $this->mapTarget($import, (string) $entry['target_account_id']), ownsBalances: true, preexisting: true);
        }

        foreach ($entries->where('action', ImportAccountAction::Merge->value) as $entry) {
            $target = $map->targetFor((string) $entry['merge_into_key']);

            if ($target !== null) {
                $map->assign($entry['key'], $target, ownsBalances: false, preexisting: $map->isPreexisting($target));
            }
        }

        $created = $entries->where('action', ImportAccountAction::Create->value)->count();

        $import->recordStats(['accounts' => [
            'created' => $created,
            'mapped' => $entries->where('action', ImportAccountAction::Map->value)->count(),
        ]]);

        return $map;
    }

    /**
     * The first account a user ever has sets their main currency
     * (`AccountUserCurrencyService::syncFromFirstAccount`), and not every
     * account currency may be a main one. So when the user starts with none,
     * an account in a currency that can be goes first.
     *
     * @param  Collection<array-key, array<string, mixed>>  $creates
     * @return Collection<array-key, array<string, mixed>>
     */
    private function inCreationOrder(Import $import, Collection $creates): Collection
    {
        if ($import->user->accounts()->exists()) {
            return $creates;
        }

        $primaryCodes = $this->currencyOptions->primaryCodes();

        return $creates->sortBy(fn (array $entry): int => in_array($entry['currency_code'] ?? null, $primaryCodes, true) ? 0 : 1)->values();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function create(Import $import, array $entry): Account
    {
        $account = $this->accountWriter->create($import->user, [
            'name' => $entry['name'],
            'type' => $entry['type'],
            'currency_code' => $entry['currency_code'],
            'bank_id' => $entry['bank_id'] ?? null,
        ], $import->space_id);

        $account->forceFill([
            'import_id' => $import->id,
            'iban' => $this->normalizeIban($entry['iban'] ?? null),
        ])->save();

        return $account;
    }

    /**
     * The request already refused anything but a manual account of the user's
     * own in this space. Checked again here because a bank could have been
     * connected to it while the import waited in the queue, and a connected
     * account must never receive imported rows.
     */
    private function mapTarget(Import $import, string $accountId): Account
    {
        $account = $import->user->accounts()->forSpace((string) $import->space_id)->find($accountId);

        if ($account === null || $account->isConnected()) {
            throw new RuntimeException('The account picked for the import is no longer a manual account of this space.');
        }

        return $account;
    }

    private function normalizeIban(?string $iban): ?string
    {
        $normalized = strtoupper((string) preg_replace('/\s+/', '', (string) $iban));

        return preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $normalized) === 1 ? $normalized : null;
    }
}

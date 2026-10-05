<?php

namespace App\Services\Imports;

use App\Enums\AccountType;
use App\Enums\ImportAccountAction;
use App\Models\Account;
use App\Models\Bank;
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
    /** @var array<string, Bank> the user's own banks this run resolved, by normalized name */
    private array $ownBanks = [];

    private int $banksCreated = 0;

    public function __construct(
        private AccountWriteService $accountWriter,
        private CurrencyOptions $currencyOptions,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $plan
     */
    public function write(Import $import, array $plan): ImportAccountMap
    {
        $this->ownBanks = [];
        $this->banksCreated = 0;

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

        $import->recordStats(['accounts' => [
            'created' => $entries->where('action', ImportAccountAction::Create->value)->count(),
            'mapped' => $entries->where('action', ImportAccountAction::Map->value)->count(),
            'banks_created' => $this->banksCreated,
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
            'bank_id' => $this->bankIdFor($import, $entry),
        ], $import->space_id);

        $account->forceFill([
            'import_id' => $import->id,
            'iban' => ImportIban::normalize($entry['iban'] ?? null),
        ])->save();

        return $account;
    }

    /**
     * The bank an account goes in: the one picked, or a bank of the user's own
     * named after the other app's when nothing in the catalog matched. Every
     * account naming the same bank shares it, and one the user already has
     * under that name is reused, so importing the file again creates none.
     *
     * @param  array<string, mixed>  $entry
     */
    private function bankIdFor(Import $import, array $entry): ?string
    {
        if (filled($entry['bank_id'] ?? null)) {
            return (string) $entry['bank_id'];
        }

        $name = trim((string) ($entry['new_bank_name'] ?? ''));

        // Cash has no bank to create, and a name of only symbols or emoji
        // has nothing to match a bank by.
        if ($name === '' || ($entry['type'] ?? null) === AccountType::Others->value || BankNameMatcher::normalize($name) === '') {
            return null;
        }

        return ($this->ownBanks[BankNameMatcher::normalize($name)] ??= $this->ownBank($import, $name))->id;
    }

    private function ownBank(Import $import, string $name): Bank
    {
        $normalized = BankNameMatcher::normalize($name);

        $existing = Bank::query()
            ->where('user_id', $import->user_id)
            ->get(['id', 'name', 'user_id'])
            ->first(fn (Bank $bank): bool => BankNameMatcher::normalize($bank->name) === $normalized);

        if ($existing !== null) {
            return $existing;
        }

        $this->banksCreated++;

        return Bank::query()->forceCreate([
            'name' => $name,
            'user_id' => $import->user_id,
            'import_id' => $import->id,
        ]);
    }

    /**
     * The request already refused anything but an account the import may
     * write into. Checked again here, under the same rules, because the
     * account could have been connected to a bank or archived while the
     * import waited in the queue, and a connected account must never receive
     * imported rows.
     */
    private function mapTarget(Import $import, string $accountId): Account
    {
        $account = ImportPlanValidator::mappableAccounts($import->user, (string) $import->space_id)->find($accountId);

        if ($account === null) {
            throw new RuntimeException('The account picked for the import can no longer receive imported transactions.');
        }

        return $account;
    }
}

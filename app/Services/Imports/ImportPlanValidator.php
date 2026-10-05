<?php

namespace App\Services\Imports;

use App\Enums\AccountType;
use App\Enums\CategoryType;
use App\Enums\ImportAccountAction;
use App\Enums\ImportCategoryAction;
use App\Enums\ImportMode;
use App\Models\Account;
use App\Models\Category;
use App\Models\User;
use App\Services\CategoryTree;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Validator;

/**
 * The checks on a full import's plan that span more than one field: which
 * accounts and categories it may point at, how its entries refer to each
 * other, and how deep the category tree it builds gets.
 */
class ImportPlanValidator
{
    /**
     * Only accounts that keep a ledger: a loan or a property needs detail
     * rows an import cannot fill in, and an investment account shows no
     * movements at all.
     */
    private const TRANSACTIONAL_TYPES = [
        AccountType::Checking,
        AccountType::Savings,
        AccountType::CreditCard,
        AccountType::Others,
    ];

    /**
     * The plan key the wizard files the rows the other app marked as ignored
     * under. Those did not count there, so here they go to a transfer, which
     * counts neither as spending nor as income.
     */
    public const IGNORED_ROWS_KEY = 'ignored';

    public function __construct(private CategoryTree $tree) {}

    /**
     * The values of {@see self::TRANSACTIONAL_TYPES}, for validation rules.
     *
     * @return list<string>
     */
    public static function transactionalTypeValues(): array
    {
        return array_map(fn (AccountType $type): string => $type->value, self::TRANSACTIONAL_TYPES);
    }

    /**
     * The accounts an import may write into: the user's own manual ones in the
     * space, still in use, that keep a ledger. A connected account never is,
     * because the bank owns what is on it.
     *
     * @return Builder<Account>
     */
    public static function mappableAccounts(User $user, string $spaceId): Builder
    {
        return Account::query()
            ->where('user_id', $user->id)
            ->forSpace($spaceId)
            ->whereNull('banking_connection_id')
            ->whereNull('archived_at')
            ->whereIn('type', self::transactionalTypeValues());
    }

    /**
     * @param  array<string, mixed>  $data  the request's input
     */
    public function validate(Validator $validator, User $user, string $spaceId, array $data): void
    {
        $accounts = collect($data['accounts'] ?? [])->filter(fn ($entry): bool => is_array($entry));
        $categories = collect($data['categories'] ?? [])->filter(fn ($entry): bool => is_array($entry));

        $this->validateAccounts($validator, $user, $spaceId, $accounts, ImportMode::tryFrom((string) ($data['mode'] ?? '')));
        $this->validateCategories($validator, $user, $spaceId, $categories);
    }

    /**
     * @param  Collection<array-key, array<mixed>>  $accounts
     */
    private function validateAccounts(Validator $validator, User $user, string $spaceId, Collection $accounts, ?ImportMode $mode): void
    {
        $actions = $accounts->pluck('action', 'key');
        $receiving = $actions->filter(fn ($action): bool => in_array($action, [ImportAccountAction::Create->value, ImportAccountAction::Map->value], true));

        if ($receiving->isEmpty()) {
            $validator->errors()->add('accounts', __('Pick at least one account to import into.'));
        }

        $mappableIds = self::mappableAccounts($user, $spaceId)->pluck('id')->flip();

        foreach ($accounts as $index => $entry) {
            $this->validateAccountEntry($validator, "accounts.{$index}", $entry, $receiving, $mappableIds, $mode);
        }
    }

    /**
     * @param  array<mixed>  $entry
     * @param  Collection<array-key, mixed>  $receiving
     * @param  Collection<string, int>  $mappableIds
     */
    private function validateAccountEntry(Validator $validator, string $path, array $entry, Collection $receiving, Collection $mappableIds, ?ImportMode $mode): void
    {
        $action = $entry['action'] ?? null;

        if ($action === ImportAccountAction::Map->value && $mode === ImportMode::Wipe) {
            $validator->errors()->add("{$path}.action", __('Starting from scratch deletes your manual accounts, so nothing can be added to one of them.'));
        }

        if ($action === ImportAccountAction::Map->value && ! $mappableIds->has((string) ($entry['target_account_id'] ?? ''))) {
            $validator->errors()->add("{$path}.target_account_id", __('Imports can only go into one of your manual accounts.'));
        }

        $mergeInto = (string) ($entry['merge_into_key'] ?? '');

        if ($action === ImportAccountAction::Merge->value && ($mergeInto === ($entry['key'] ?? null) || ! $receiving->has($mergeInto))) {
            $validator->errors()->add("{$path}.merge_into_key", __('An account can only be merged into one that is imported.'));
        }
    }

    /**
     * @param  Collection<array-key, array<mixed>>  $categories
     */
    private function validateCategories(Validator $validator, User $user, string $spaceId, Collection $categories): void
    {
        $ownTypes = Category::query()->where('user_id', $user->id)->forSpace($spaceId)->pluck('type', 'id');
        $parentMap = $this->tree->parentMap($user->id);
        $byKey = $categories->keyBy('key');

        foreach ($categories as $index => $entry) {
            $path = "categories.{$index}";

            if (($entry['action'] ?? null) === ImportCategoryAction::Match->value && ! $ownTypes->has((string) ($entry['category_id'] ?? ''))) {
                $validator->errors()->add("{$path}.category_id", __('That category is not one of yours.'));
            }

            if (($entry['action'] ?? null) === ImportCategoryAction::Create->value) {
                $this->validateCreatedCategory($validator, $path, $entry, $byKey, $parentMap, $user);
            }

            if (($entry['key'] ?? null) === self::IGNORED_ROWS_KEY && ! $this->isTransfer($entry, $ownTypes)) {
                $validator->errors()->add("{$path}.category_id", __('Ignored movements go to a transfer category.'));
            }
        }
    }

    /**
     * Whether a plan entry lands on a transfer category, one of the user's or
     * a new root.
     *
     * @param  array<mixed>  $entry
     * @param  Collection<string, mixed>  $ownTypes  category id => type
     */
    private function isTransfer(array $entry, Collection $ownTypes): bool
    {
        $type = ($entry['action'] ?? null) === ImportCategoryAction::Match->value
            ? $ownTypes->get((string) ($entry['category_id'] ?? ''))
            : CategoryType::tryFrom((string) ($entry['type'] ?? ''));

        return $type === CategoryType::Transfer && ($entry['parent_key'] ?? null) === null;
    }

    /**
     * @param  array<mixed>  $entry
     * @param  Collection<array-key, array<mixed>>  $byKey
     * @param  array<string, ?string>  $parentMap
     */
    private function validateCreatedCategory(Validator $validator, string $path, array $entry, Collection $byKey, array $parentMap, User $user): void
    {
        $parentKey = $entry['parent_key'] ?? null;

        if ($parentKey === null && blank($entry['type'] ?? null)) {
            $validator->errors()->add("{$path}.type", __('A new category needs a type.'));
        }

        if ($parentKey !== null && (! $byKey->has($parentKey) || $parentKey === ($entry['key'] ?? null))) {
            $validator->errors()->add("{$path}.parent_key", __('The parent category is not part of the import.'));

            return;
        }

        if ($this->depthOf($entry, $byKey, $parentMap, $user) > Category::MAX_DEPTH) {
            $validator->errors()->add("{$path}.parent_key", __('Categories can only be nested :depth levels deep.', ['depth' => Category::MAX_DEPTH]));
        }
    }

    /**
     * How deep an entry would sit once created: its chain of plan parents,
     * plus the depth of the user's own category that chain hangs from.
     *
     * @param  array<mixed>  $entry
     * @param  Collection<array-key, array<mixed>>  $byKey
     * @param  array<string, ?string>  $parentMap
     */
    private function depthOf(array $entry, Collection $byKey, array $parentMap, User $user): int
    {
        $depth = 1;
        $current = $entry;

        while (($parentKey = $current['parent_key'] ?? null) !== null && $depth <= Category::MAX_DEPTH) {
            $current = $byKey->get($parentKey);

            if ($current === null) {
                break;
            }

            if (($current['action'] ?? null) === ImportCategoryAction::Match->value) {
                return $depth + count($this->tree->ancestorAndSelfIds($user->id, (string) ($current['category_id'] ?? ''), $parentMap));
            }

            $depth++;
        }

        return $depth;
    }
}

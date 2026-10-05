<?php

namespace App\Services\Imports;

use App\Actions\CreateDefaultCategories;
use App\Enums\CategoryType;
use App\Enums\ImportSource;
use App\Enums\ImportStatus;
use App\Models\Account;
use App\Models\Category;
use App\Models\Import;
use App\Models\User;
use App\Services\Ai\AiCategorizationGate;
use Illuminate\Support\Collection;

/**
 * What the wizard and the Settings page read about a user's imports: the
 * state of one import, the history list with what undoing each would remove,
 * and the context the wizard starts from.
 */
class ImportHistoryPresenter
{
    /** The history Settings lists: enough to recognise an import, not every one ever made. */
    private const HISTORY_LIMIT = 20;

    public function __construct(private AiCategorizationGate $gate) {}

    /**
     * @return array<string, mixed>
     */
    public function status(Import $import): array
    {
        return [
            'id' => $import->id,
            'source' => $import->source->value,
            'mode' => $import->mode->value,
            'status' => $import->status->value,
            'file_name' => $import->file_name,
            'stats' => $import->stats ?? [],
            'error' => $import->error,
            'created_at' => $import->created_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'undone_at' => $import->undone_at?->toIso8601String(),
        ];
    }

    /**
     * Every import worth listing, newest first, each with what undoing it
     * would take out right now: an account created by the import may have
     * been deleted by hand since, and rows added to it by hand go too.
     *
     * @return list<array<string, mixed>>
     */
    public function history(User $user): array
    {
        return $user->imports()
            ->where('status', '!=', ImportStatus::Draft->value)
            ->latest()
            ->limit(self::HISTORY_LIMIT)
            ->get()
            ->map(fn (Import $import): array => [
                ...$this->status($import),
                'undoable' => $import->isUndoable(),
                'summary' => $import->isUndoable() ? $this->undoSummary($import) : null,
            ])
            ->all();
    }

    /**
     * What the wizard needs before it reads the file: the space's accounts
     * (with how much each holds, for the "start from scratch" warning), its
     * categories, whether the AI will pick up what stays uncategorized, and
     * the column layout each source was last imported with.
     *
     * @return array<string, mixed>
     */
    public function context(User $user): array
    {
        $spaceId = $user->activeSpace()->id;
        $categories = $user->categories()->forSpace($spaceId)->forDisplay()->get();

        return [
            'inOnboarding' => ! $user->isOnboarded(),
            'aiAvailable' => $this->gate->allows($user),
            'running' => $user->imports()->running()->value('id'),
            'accounts' => $this->accounts($user, $spaceId),
            'mappableAccountIds' => ImportPlanValidator::mappableAccounts($user, $spaceId)->pluck('id')->all(),
            'categories' => $categories,
            'defaultCategoryNames' => $this->defaultCategoryNames(),
            'transferTargets' => [
                'own' => $this->transferTarget($user, $categories, 'Own account'),
                'ignored' => $this->transferTarget($user, $categories, 'Other transfers'),
            ],
            'profiles' => $this->profiles($user),
        ];
    }

    /**
     * Every seeded category under both of its names, so a Spanish file's
     * "Seguros" finds an account seeded in English as "Insurance".
     *
     * @return list<array{en: string, es: string}>
     */
    private function defaultCategoryNames(): array
    {
        $spanish = CreateDefaultCategories::getDefaultCategories('es');

        return array_map(
            fn (array $category, int $index): array => ['en' => $category['name'], 'es' => $spanish[$index]['name']],
            CreateDefaultCategories::getDefaultCategories('en'),
            array_keys(CreateDefaultCategories::getDefaultCategories('en')),
        );
    }

    /**
     * Where a kind of transfer goes by default: the seeded transfer category of
     * that name when the user still has it, in either language, or the one to
     * create in its place, named in the user's own.
     *
     * @param  Collection<int, Category>  $categories
     * @return array{category_id: ?string, name: string, icon: string, color: string}
     */
    private function transferTarget(User $user, Collection $categories, string $canonicalName): array
    {
        $names = [mb_strtolower($canonicalName), mb_strtolower(CreateDefaultCategories::localizedCategoryName($canonicalName, 'es'))];
        $seeded = collect(CreateDefaultCategories::getDefaultCategories('en'))->firstWhere('name', $canonicalName);

        $existing = $categories->first(fn (Category $category): bool => $category->type === CategoryType::Transfer
            && in_array(mb_strtolower($category->name), $names, true));

        return [
            'category_id' => $existing?->id,
            'name' => CreateDefaultCategories::localizedCategoryName($canonicalName, $user->preferredLocale()),
            'icon' => $seeded['icon'] ?? 'ArrowLeftRight',
            'color' => $seeded['color'] ?? 'stone',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function undoSummary(Import $import): array
    {
        $created = $import->accounts()->withCount('transactions')->orderBy('name')->get(['id', 'name']);

        $intoOwn = $import->transactions()
            ->whereNotIn('account_id', $created->pluck('id'))
            ->selectRaw('account_id, count(*) as aggregate')
            ->groupBy('account_id')
            ->pluck('aggregate', 'account_id');

        $onCreated = $created->sum(fn (Account $account): int => (int) $account->getAttribute('transactions_count'));
        $importedOnCreated = $import->transactions()->whereIn('account_id', $created->pluck('id'))->count();

        return [
            // Movements on the accounts the import created that it did not
            // write itself: added by hand or by a later import. Undo deletes
            // them with the account, and the dialog has to say so.
            'later_transactions' => max(0, $onCreated - $importedOnCreated),
            'accounts' => $created->map(fn (Account $account): array => [
                'name' => $account->name,
                'transactions' => (int) $account->getAttribute('transactions_count'),
            ])->all(),
            'categories' => $import->categories()->count(),
            'transactions' => $import->transactions()->count(),
            'balances' => $import->balances()->count(),
            'into_own_accounts' => $this->ownAccountLines($intoOwn),
        ];
    }

    /**
     * @param  Collection<string, int|string>  $countsByAccount
     * @return list<array{name: string, transactions: int}>
     */
    private function ownAccountLines(Collection $countsByAccount): array
    {
        if ($countsByAccount->isEmpty()) {
            return [];
        }

        return Account::query()
            ->whereIn('id', $countsByAccount->keys())
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Account $account): array => [
                'name' => $account->name,
                'transactions' => (int) $countsByAccount[$account->id],
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function accounts(User $user, string $spaceId): array
    {
        return $user->accounts()
            ->forSpace($spaceId)
            ->with('bank:id,name,logo')
            ->withCount('transactions')
            ->orderBy('name')
            ->get()
            ->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'currency_code' => $account->currency_code,
                'connected' => $account->isConnected(),
                'archived' => $account->isArchived(),
                'transactions_count' => (int) $account->getAttribute('transactions_count'),
                'bank' => $account->bank?->only(['id', 'name', 'logo']),
            ])
            ->all();
    }

    /**
     * The layout each source was last imported with, so the second file from
     * the same app is not mapped by hand again.
     *
     * @return array<string, array<string, mixed>|null>
     */
    private function profiles(User $user): array
    {
        $profiles = [];

        foreach (ImportSource::cases() as $source) {
            $profiles[$source->value] = $user->imports()
                ->where('source', $source->value)
                ->whereNotNull('options')
                ->latest()
                ->value('options')['profile'] ?? null;
        }

        return $profiles;
    }
}

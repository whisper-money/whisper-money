<?php

namespace App\Services\Transactions;

use App\Actions\CreateDefaultCategories;
use App\Enums\LabelSource;
use App\Enums\QuickExpense;
use App\Models\Account;
use App\Models\Category;
use App\Models\Label;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Transactions a user typed in as a rough monthly figure rather than read from
 * a file or a bank: "rent is about €900". They are ordinary transactions,
 * editable and deletable like any other, told apart only by the "Estimate"
 * label they carry — which is what lets a later import of the same month warn
 * that they would count that month twice.
 *
 * The label is told apart by its source rather than its name, so renaming it
 * keeps it working and a label the user made called "Estimate" is never
 * mistaken for it. It is still a label the user manages like any other.
 */
class EstimateTransactions
{
    /** The label's name, as an English source string to translate. */
    private const LABEL = 'Estimate';

    /** Its name when the user already has a label called "Estimate" of their own. */
    private const FALLBACK_LABEL = 'Onboarding estimate';

    /** Locales a default category may have been seeded in. */
    private const LOCALES = ['en', 'es', 'fr'];

    public function __construct(private TransactionWriter $writer) {}

    /**
     * Store each expense as one outgoing transaction on the first day of last
     * month, in the user's own calendar, so the month reads as a whole month
     * spent rather than one still running.
     *
     * Saving again replaces the estimates already on that account for that
     * month: a retried request or a second go at the screen is the user's
     * latest answer, not more spending.
     *
     * @param  array<string, int>  $amounts  positive amounts in the account currency's minor units, keyed by {@see QuickExpense} value
     * @return Collection<int, Transaction>
     */
    public function record(User $user, Account $account, array $amounts): Collection
    {
        $month = $user->localToday()->startOfMonth()->subMonthNoOverflow();
        $locale = $user->locale ?? app()->getLocale();

        return DB::transaction(function () use ($user, $account, $amounts, $month, $locale): Collection {
            foreach ($this->inMonths($account, [$month->format('Y-m')]) as $estimate) {
                $this->writer->delete($estimate, updateBalance: false);
            }

            $label = $this->labelFor($user, $account, $locale);
            $categories = $this->categoriesFor($user, $account);

            return collect($amounts)
                ->map(function (int $amount, string $kind) use ($user, $account, $label, $month, $locale, $categories): Transaction {
                    $expense = QuickExpense::from($kind);

                    return $this->writer->create($user, $account->space, $account, [
                        'description' => __($expense->description(), [], $locale),
                        'transaction_date' => $month->toDateString(),
                        'amount' => -$amount,
                        'category_id' => $this->categoryIdIn($categories, $expense),
                    ], [$label->id], updateBalance: false)->transaction;
                })
                ->values();
        });
    }

    /**
     * The estimates already on an account in any of the given months.
     *
     * @param  list<string>  $months  `Y-m`
     * @return Collection<int, Transaction>
     */
    public function inMonths(Account $account, array $months): Collection
    {
        if ($months === []) {
            return collect();
        }

        $starts = collect($months)->map(fn (string $month): Carbon => Carbon::createFromFormat('!Y-m', $month));

        // One range over the whole span, narrowed to the months themselves in
        // memory: a file of several years would otherwise be a clause a month.
        return $this->estimates()
            ->where('account_id', $account->id)
            ->whereBetween('transaction_date', [
                $starts->min()->toDateString(),
                $starts->max()->endOfMonth()->toDateString(),
            ])
            ->orderBy('transaction_date')
            ->get()
            ->filter(fn (Transaction $transaction): bool => in_array($transaction->transaction_date->format('Y-m'), $months, true))
            ->values();
    }

    /**
     * The estimates a user holds since the given date, as the month (`Y-m`)
     * each one is in, keyed by its id: enough to tell which months hold
     * estimates and whether a month holds nothing else.
     *
     * @return array<string, string>
     */
    public function monthsById(User $user, CarbonInterface $since): array
    {
        return $this->estimates()
            ->where('user_id', $user->id)
            ->where('transaction_date', '>=', $since->toDateString())
            ->get(['id', 'transaction_date'])
            ->mapWithKeys(fn (Transaction $estimate): array => [$estimate->id => $estimate->transaction_date->format('Y-m')])
            ->all();
    }

    /**
     * @return Builder<Transaction>
     */
    private function estimates(): Builder
    {
        return Transaction::query()
            ->whereHas('labels', fn (Builder $query) => $query->where('source', LabelSource::Estimate));
    }

    /**
     * The user's "Estimate" label in the account's space, created the first
     * time it is needed.
     */
    private function labelFor(User $user, Account $account, string $locale): Label
    {
        $existing = Label::query()
            ->where('user_id', $user->id)
            ->forSpace($account->space_id)
            ->where('source', LabelSource::Estimate)
            ->first();

        return $existing ?? Label::query()->create([
            'user_id' => $user->id,
            'space_id' => $account->space_id,
            'name' => $this->freeLabelName($user, $locale),
            'color' => 'amber',
            'source' => LabelSource::Estimate,
        ]);
    }

    /**
     * "Estimate", unless the user already has a label of their own by that
     * name: names are unique per user, and theirs is not ours to take over.
     */
    private function freeLabelName(User $user, string $locale): string
    {
        $name = __(self::LABEL, [], $locale);
        $taken = Label::query()->where('user_id', $user->id)->where('name', $name)->exists();

        return $taken ? __(self::FALLBACK_LABEL, [], $locale) : $name;
    }

    /**
     * The user's default categories an expense can be filed under, id by name.
     *
     * @return Collection<string, string>
     */
    private function categoriesFor(User $user, Account $account): Collection
    {
        return Category::query()
            ->where('user_id', $user->id)
            ->forSpace($account->space_id)
            ->whereIn('name', collect(QuickExpense::cases())->flatMap(fn (QuickExpense $expense): array => $this->categoryNames($expense))->all())
            ->pluck('id', 'name');
    }

    /**
     * The category an expense belongs to, or none when the user renamed or
     * deleted it: the estimate still counts, it is just left to categorize.
     *
     * @param  Collection<string, string>  $categories
     */
    private function categoryIdIn(Collection $categories, QuickExpense $expense): ?string
    {
        return collect($this->categoryNames($expense))
            ->map(fn (string $name): ?string => $categories->get($name))
            ->first(fn (?string $id): bool => $id !== null);
    }

    /**
     * The names a default category may carry: it is seeded in the language the
     * user signed up in, which is not necessarily the one they read today.
     *
     * @return list<string>
     */
    private function categoryNames(QuickExpense $expense): array
    {
        $name = $expense->defaultCategory();

        return array_values(array_unique(array_map(
            fn (string $locale): string => CreateDefaultCategories::localizedCategoryName($name, $locale),
            self::LOCALES,
        )));
    }
}

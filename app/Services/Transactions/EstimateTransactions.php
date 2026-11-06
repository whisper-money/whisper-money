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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Transactions a user typed in as a rough monthly figure rather than read from
 * a file or a bank: "rent is about €900". They are ordinary transactions,
 * editable and deletable like any other, told apart only by the "Estimate"
 * label they carry — which is what lets a later import of the same month warn
 * that they would count that month twice.
 */
class EstimateTransactions
{
    /** The label's name, as an English source string to translate. */
    public const LABEL = 'Estimate';

    /** Locales the label may have been created in, so it is found in any of them. */
    private const LOCALES = ['en', 'es', 'fr'];

    public function __construct(private TransactionWriter $writer) {}

    /**
     * Store each expense as one outgoing transaction on the first day of last
     * month, in the user's own calendar, so the month reads as a whole month
     * spent rather than one still running.
     *
     * @param  array<string, int>  $amounts  positive amounts in the account currency's minor units, keyed by {@see QuickExpense} value
     * @return Collection<int, Transaction>
     */
    public function record(User $user, Account $account, array $amounts): Collection
    {
        $space = $account->space;
        $label = $this->labelFor($user, $account);
        $date = $user->localToday()->startOfMonth()->subMonthNoOverflow()->toDateString();
        $locale = $user->locale ?? app()->getLocale();

        return collect($amounts)
            ->map(function (int $amount, string $kind) use ($user, $space, $account, $label, $date, $locale): Transaction {
                $expense = QuickExpense::from($kind);

                return $this->writer->create($user, $space, $account, [
                    'description' => __($expense->description(), [], $locale),
                    'transaction_date' => $date,
                    'amount' => -$amount,
                    'category_id' => $this->categoryIdFor($user, $account, $expense),
                ], [$label->id], updateBalance: false)->transaction;
            })
            ->values();
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

        return $this->estimates()
            ->where('account_id', $account->id)
            ->where(function ($query) use ($months): void {
                foreach ($months as $month) {
                    $start = Carbon::createFromFormat('!Y-m', $month);

                    $query->orWhereBetween('transaction_date', [
                        $start->toDateString(),
                        $start->copy()->endOfMonth()->toDateString(),
                    ]);
                }
            })
            ->orderBy('transaction_date')
            ->get();
    }

    /**
     * The months (`Y-m`) a user holds estimates in. A month of estimates is a
     * whole month by construction, however few rows it has.
     *
     * @return Collection<int, string>
     */
    public function monthsFor(User $user): Collection
    {
        return $this->estimates()
            ->where('user_id', $user->id)
            ->pluck('transaction_date')
            ->map(fn (Carbon $date): string => $date->format('Y-m'))
            ->unique()
            ->values();
    }

    /**
     * @return Builder<Transaction>
     */
    private function estimates(): Builder
    {
        return Transaction::query()
            ->whereHas('labels', fn ($query) => $query->whereIn('name', $this->labelNames()));
    }

    /**
     * The user's "Estimate" label in the account's space, created the first
     * time it is needed.
     */
    private function labelFor(User $user, Account $account): Label
    {
        $existing = Label::query()
            ->where('user_id', $user->id)
            ->forSpace($account->space_id)
            ->whereIn('name', $this->labelNames())
            ->first();

        return $existing ?? Label::query()->create([
            'user_id' => $user->id,
            'space_id' => $account->space_id,
            'name' => __(self::LABEL, [], $user->locale ?? app()->getLocale()),
            'color' => 'amber',
            'source' => LabelSource::User,
        ]);
    }

    /**
     * The category an expense belongs to among the user's own, or none when
     * they renamed or deleted it.
     */
    private function categoryIdFor(User $user, Account $account, QuickExpense $expense): ?string
    {
        $name = $expense->defaultCategory();

        return Category::query()
            ->where('user_id', $user->id)
            ->forSpace($account->space_id)
            ->whereIn('name', array_unique([$name, CreateDefaultCategories::localizedCategoryName($name, 'es')]))
            ->value('id');
    }

    /**
     * @return list<string>
     */
    private function labelNames(): array
    {
        return array_values(array_unique(array_map(
            fn (string $locale): string => __(self::LABEL, [], $locale),
            self::LOCALES,
        )));
    }
}

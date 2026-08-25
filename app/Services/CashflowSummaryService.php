<?php

namespace App\Services;

use App\Enums\CategoryType;
use App\Models\Transaction;
use App\Services\Concerns\ConvertsTransactionCurrency;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class CashflowSummaryService
{
    use ConvertsTransactionCurrency;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    /**
     * Derive the summary from already-clamped income and expense totals (both
     * non-negative, in minor units).
     *
     * @return array{income: int, expense: int, net: int, savings_rate: float|int}
     */
    private static function summarize(int $income, int $expense): array
    {
        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savings_rate' => $income > 0 ? round((($income - $expense) / $income) * 100, 1) : 0,
        ];
    }

    /**
     * A period's cashflow summary alongside the one before it, in the user's
     * currency. The dashboard widget and the cashflow screen both read this, so
     * the same month cannot come out two different ways.
     *
     * @return array{current: array<string, mixed>, previous: array<string, mixed>}
     */
    public function forComparedPeriods(string $userId, string $userCurrency, PeriodComparator $period, PeriodComparator $previousPeriod): array
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$previousPeriod->from, $period->to])
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        return [
            'current' => $this->forTransactions(
                $this->transactionsForPeriod($transactions, $period->from, $period->to),
                $userCurrency,
            ),
            'previous' => $this->forTransactions(
                $this->transactionsForPeriod($transactions, $previousPeriod->from, $previousPeriod->to),
                $userCurrency,
            ),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function forTransactions(Collection $transactions, string $userCurrency): array
    {
        $income = max(0, $this->sumTransactions($transactions, $userCurrency, CategoryType::Income));
        $expense = max(0, -$this->sumTransactions($transactions, $userCurrency, CategoryType::Expense));

        return [
            ...self::summarize($income, $expense),
            'savings' => $this->sumOutflowTransactions($transactions, $userCurrency, CategoryType::Savings),
            'investments' => $this->sumOutflowTransactions($transactions, $userCurrency, CategoryType::Investment),
        ];
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function sumTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        $onSide = match ($type) {
            CategoryType::Income => fn (Transaction $transaction): bool => $transaction->isIncomeSide(),
            CategoryType::Expense => fn (Transaction $transaction): bool => $transaction->isExpenseSide(),
            default => throw new InvalidArgumentException("sumTransactions only supports Income and Expense, got {$type->value}."),
        };

        return $transactions
            ->filter($onSide)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency));
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function sumOutflowTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        return abs($transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->categoryType() === $type
                && $transaction->amount < 0)
            ->sum(fn (Transaction $transaction): int => $this->convertTransactionAmount($transaction, $userCurrency)));
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return Collection<int, Transaction>
     */
    private function transactionsForPeriod(Collection $transactions, Carbon $from, Carbon $to): Collection
    {
        return $transactions->filter(
            fn (Transaction $transaction): bool => $transaction->transaction_date->betweenIncluded($from, $to)
        );
    }
}

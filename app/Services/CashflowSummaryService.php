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
     * Nobody keeps more than everything that came in. The formula can say
     * otherwise once expenses go negative — a month of refunds, or a row whose
     * sign the bank got the wrong way round — and "savings rate 488.1%" reads
     * as a broken screen rather than as the good news it is dressed up as. The
     * floor stays open: spending three times your income is a real -200%.
     */
    private const MAX_SAVINGS_RATE = 100;

    /**
     * Derive the summary from signed income and expense totals, in minor units.
     * Either side can be negative when a period's reversals outweigh what they
     * reverse, and the net says so rather than hiding it.
     *
     * @return array{income: int, expense: int, net: int, savings_rate: float|int}
     */
    private static function summarize(int $income, int $expense): array
    {
        return [
            'income' => $income,
            'expense' => $expense,
            'net' => $income - $expense,
            'savings_rate' => $income > 0
                ? min(self::MAX_SAVINGS_RATE, round((($income - $expense) / $income) * 100, 1))
                : 0,
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
            ->countingTowardsTotals()
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
     * The same summary, one row per calendar month between two dates, keyed by
     * YYYY-MM. One query for the whole range instead of one per month: the
     * monthly summary needs a year of savings rates to draw a streak and a
     * sparkline, and twelve round trips for that would be twelve too many.
     *
     * @return array<string, array<string, mixed>>
     */
    public function forMonths(string $userId, string $userCurrency, Carbon $from, Carbon $to): array
    {
        $transactions = Transaction::query()
            ->where('transactions.user_id', $userId)
            ->whereBetween('transactions.transaction_date', [$from->copy()->startOfMonth(), $to->copy()->endOfMonth()])
            ->countingTowardsTotals()
            ->with(['account', 'category'])
            ->get();

        $this->preloadExchangeRates($transactions, $userCurrency);

        $months = [];
        $cursor = $from->copy()->startOfMonth();

        while ($cursor->lte($to)) {
            $months[$cursor->format('Y-m')] = $this->forTransactions(
                $this->transactionsForPeriod($transactions, $cursor->copy()->startOfMonth(), $cursor->copy()->endOfMonth()),
                $userCurrency,
            );

            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     * @return array<string, mixed>
     */
    private function forTransactions(Collection $transactions, string $userCurrency): array
    {
        // Deliberately unclamped: a month whose refunds outweigh its spending
        // has negative expenses, and clamping that to zero is the one place
        // money goes missing from the net.
        $income = $this->sumTransactions($transactions, $userCurrency, CategoryType::Income);
        $expense = -$this->sumTransactions($transactions, $userCurrency, CategoryType::Expense);

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

        return $this->sumConvertedAmounts($transactions->filter($onSide), $userCurrency);
    }

    /**
     * @param  Collection<int, Transaction>  $transactions
     */
    private function sumOutflowTransactions(Collection $transactions, string $userCurrency, CategoryType $type): int
    {
        return abs($this->sumConvertedAmounts(
            $transactions->filter(fn (Transaction $transaction): bool => $transaction->categoryType() === $type
                && $transaction->amount < 0),
            $userCurrency,
        ));
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

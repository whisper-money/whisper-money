<?php

namespace App\Services\CreditCards;

use App\Enums\CategoryType;
use App\Features\CreditCardStatements;
use App\Models\Account;
use App\Models\CreditCardDetail;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Concerns\ConvertsTransactionCurrency;
use App\Services\ExchangeRateService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use Laravel\Pennant\Feature;

/**
 * Estimates what a credit card will charge next, and when, from its statement
 * dates and its own ledger. Computed on every read and never written back, so
 * nothing it says can be counted twice anywhere else.
 */
class CreditCardStatementService
{
    use ConvertsTransactionCurrency;

    public function __construct(private ExchangeRateService $exchangeRateService) {}

    public function isAvailableTo(User $user): bool
    {
        return Feature::for($user)->active(CreditCardStatements::class);
    }

    /**
     * A credit card's statement dates and, once they are set, the estimate
     * they give as of today in the user's timezone. Reads the
     * `creditCardDetail` relation, so eager-load it when presenting several
     * cards.
     *
     * @return array{
     *     credit_card_detail: array{statement_closing_date: string, payment_due_date: string}|null,
     *     credit_card_statement: array<string, mixed>|null,
     * }
     */
    public function present(Account $account, User $user): array
    {
        $detail = $account->creditCardDetail;

        if ($detail === null) {
            return ['credit_card_detail' => null, 'credit_card_statement' => null];
        }

        $today = CarbonImmutable::parse(now($user->timezone ?? config('app.timezone'))->toDateString());

        return [
            'credit_card_detail' => [
                'statement_closing_date' => $detail->statement_closing_date->toDateString(),
                'payment_due_date' => $detail->payment_due_date->toDateString(),
            ],
            'credit_card_statement' => $this->estimate($account, $detail, $today),
        ];
    }

    /**
     * @return array{
     *     next_payment: array{period_from: string, closing_date: string, due_date: string, amount: int, is_final: bool},
     *     current_cycle: array{period_from: string, closing_date: string, due_date: string, amount: int},
     * }
     */
    public function estimate(Account $account, CreditCardDetail $detail, CarbonImmutable $today): array
    {
        $schedule = StatementSchedule::fromDetail($detail);
        $nextPayment = $schedule->nextPaymentOn($today);
        $currentCycle = $schedule->openCycleOn($today);

        $transactions = $this->chargeableTransactions($account, $nextPayment['cycle']->periodFrom, $currentCycle->closingDate);

        return [
            'next_payment' => [
                ...$nextPayment['cycle']->toArray(),
                'amount' => $this->amountToPay($transactions, $nextPayment['cycle'], $account->currency_code),
                'is_final' => $nextPayment['is_final'],
            ],
            'current_cycle' => [
                ...$currentCycle->toArray(),
                'amount' => $this->amountToPay($transactions, $currentCycle, $account->currency_code),
            ],
        ];
    }

    /**
     * The card's rows that end up on a statement between two dates, read on the
     * date the bank booked them: users move `transaction_date` to tidy their
     * cashflow, while `source_date` keeps the bank's.
     *
     * Repayments are left out: rows in a transfer category, and uncategorized
     * inflows, which on a card are almost always the repayment itself. Both
     * are filtered in SQL so a card costs one query, however many are listed.
     *
     * @return Collection<int, Transaction>
     */
    private function chargeableTransactions(Account $account, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $transactions = $account->transactions()
            ->leftJoin('categories', fn (JoinClause $join) => $join
                ->on('categories.id', '=', 'transactions.category_id')
                ->whereNull('categories.deleted_at'))
            ->whereRaw('COALESCE(transactions.source_date, transactions.transaction_date) BETWEEN ? AND ?', [$from->toDateString(), $to->toDateString()])
            ->where(fn (Builder $query) => $query
                ->whereNull('categories.type')
                ->orWhere('categories.type', '!=', CategoryType::Transfer->value))
            ->where(fn (Builder $query) => $query
                ->whereNotNull('transactions.category_id')
                ->orWhere('transactions.amount', '<=', 0))
            ->select('transactions.*')
            ->get()
            ->each(fn (Transaction $transaction) => $transaction->setRelation('account', $account));

        $this->preloadExchangeRates($transactions, $account->currency_code);

        return $transactions;
    }

    /**
     * What the cycle adds up to as an amount to pay: purchases are negative on
     * the ledger, so the signed sum is flipped, and refunds bring it down. The
     * whole amount counts, whatever share of the card the user owns, because
     * the bank charges all of it.
     *
     * @param  Collection<int, Transaction>  $transactions
     */
    private function amountToPay(Collection $transactions, StatementCycle $cycle, string $currency): int
    {
        return -$transactions
            ->filter(fn (Transaction $transaction): bool => $cycle->contains(self::bookedOn($transaction)))
            ->sum(fn (Transaction $transaction): int => $this->convertFullTransactionAmount($transaction, $currency));
    }

    private static function bookedOn(Transaction $transaction): CarbonImmutable
    {
        return CarbonImmutable::parse(($transaction->source_date ?? $transaction->transaction_date)->toDateString());
    }
}

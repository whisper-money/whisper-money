<?php

namespace App\Services;

use App\Enums\AccountType;
use App\Jobs\GenerateHistoricalLoanBalancesJob;
use App\Jobs\GenerateHistoricalRealEstateBalancesJob;
use App\Models\Account;
use App\Models\User;
use Carbon\Carbon;

/**
 * Creating and editing an account, shared by the settings controller and the
 * MCP write tools. Both callers arrive with an already-validated payload and
 * differ only in how they report a problem back, so nothing here throws or
 * redirects: `update()` returns the loan fields it still needs and lets the
 * caller phrase the error.
 */
class AccountWriteService
{
    public function __construct(
        private RealEstateBalanceGeneratorService $realEstateBalanceGenerator,
        private LoanBalanceGeneratorService $loanBalanceGenerator,
        private AccountUserCurrencyService $accountUserCurrencyService,
    ) {}

    /**
     * Create an account with its balance, its type detail and the balance
     * history implied by them.
     *
     * `$spaceId` is for callers that know which space to write to (the MCP
     * tools resolve one per call); left null, the account falls back to the
     * owner's current space through BelongsToSpace.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(User $user, array $data, ?string $spaceId = null): Account
    {
        $balance = $data['balance'] ?? null;

        $account = $user->accounts()->create([
            ...$this->accountAttributes($data),
            ...$spaceId !== null ? ['space_id' => $spaceId] : [],
        ]);

        if ($balance !== null) {
            $account->balances()->create([
                'balance_date' => now()->toDateString(),
                'balance' => $balance,
            ]);
        }

        if ($account->type === AccountType::RealEstate) {
            $this->createRealEstateDetail($account, $data, $balance);
        }

        if ($account->type === AccountType::Loan) {
            $this->createLoanDetail($account, $data, $balance);
            $this->linkToRealEstateAccount($user, $account, $data['linked_real_estate_account_id'] ?? null);
        }

        $this->accountUserCurrencyService->syncFromFirstAccount($account);

        return $account;
    }

    /**
     * Update an account and its type detail. Returns the loan fields still
     * missing when the account has no loan detail yet and there is not enough
     * to create one with, so the caller can report them and nothing is
     * half-created.
     *
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function update(Account $account, array $data): array
    {
        $account->update($this->accountAttributes($data));

        if ($account->type === AccountType::RealEstate) {
            $realEstateData = $this->realEstateAttributes($data);

            if ($realEstateData !== []) {
                $account->realEstateDetail()->updateOrCreate(
                    ['account_id' => $account->id],
                    $realEstateData,
                );
            }
        }

        if ($account->type === AccountType::Loan) {
            return $this->syncLoanDetail($account, $data);
        }

        return [];
    }

    /**
     * The account's own columns. Encryption is gone, so every write clears the
     * legacy flags rather than leaving stale ones behind.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function accountAttributes(array $data): array
    {
        return [
            ...collect($data)->only([
                'name', 'bank_id', 'currency_code', 'type',
                'ownership_percentage', 'ownership_applies_to_balance',
            ])->toArray(),
            'encrypted' => false,
            'name_iv' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function realEstateAttributes(array $data): array
    {
        return collect($data)->only([
            'property_type', 'address', 'purchase_price', 'purchase_date',
            'area_value', 'area_unit', 'linked_loan_account_id', 'notes',
            'revaluation_percentage',
        ])->filter(fn ($value) => $value !== null)->toArray();
    }

    /**
     * The loan's own columns, defaulting the start date to what the user sent.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function loanAttributes(array $data): array
    {
        $loanData = collect($data)->only([
            'annual_interest_rate', 'loan_term_months', 'original_amount',
        ])->filter(fn ($value) => $value !== null)->toArray();

        $loanStartDate = $data['loan_start_date'] ?? null;

        if ($loanStartDate) {
            $loanData['start_date'] = $loanStartDate;
        }

        return $loanData;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createRealEstateDetail(Account $account, array $data, ?int $balance): void
    {
        $realEstateData = $this->realEstateAttributes($data);

        if ($realEstateData !== []) {
            $account->realEstateDetail()->create($realEstateData);
        }

        // Historical balances need both ends of the line: what it was bought for
        // and what it is worth now.
        if ($balance === null || ! isset($data['purchase_price'], $data['purchase_date'])) {
            return;
        }

        $this->backfillHistoricalBalances(
            Carbon::parse($data['purchase_date']),
            fn (Carbon $from) => $this->realEstateBalanceGenerator->generateHistoricalBalances(
                $account,
                $data['purchase_price'],
                Carbon::parse($data['purchase_date']),
                $balance,
                from: $from,
            ),
            fn (Carbon $until) => GenerateHistoricalRealEstateBalancesJob::dispatch(
                $account,
                $data['purchase_price'],
                Carbon::parse($data['purchase_date']),
                $balance,
                Carbon::parse($data['purchase_date']),
                $until,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createLoanDetail(Account $account, array $data, ?int $balance): void
    {
        $loanData = $this->loanAttributes($data);

        if (! isset($loanData['annual_interest_rate'], $loanData['loan_term_months'], $loanData['original_amount'])) {
            return;
        }

        $loanData['start_date'] ??= now()->toDateString();

        $loanDetail = $account->loanDetail()->create($loanData);

        if ($balance === null) {
            return;
        }

        $startDate = Carbon::parse($loanDetail->start_date);

        $this->backfillHistoricalBalances(
            $startDate,
            fn (Carbon $from) => $this->loanBalanceGenerator->generateHistoricalBalances(
                $account,
                (int) $loanDetail->original_amount,
                $startDate,
                $balance,
                from: $from,
            ),
            fn (Carbon $until) => GenerateHistoricalLoanBalancesJob::dispatch(
                $account,
                (int) $loanDetail->original_amount,
                $startDate,
                $balance,
                $startDate,
                $until,
            ),
        );
    }

    /**
     * Fill in the balance history for an account that existed before we knew about
     * it: the last twelve months now, so the chart is populated on the next
     * render, and anything older on the queue.
     *
     * @param  callable(Carbon): mixed  $generateRecent  receives the month to start from
     * @param  callable(Carbon): mixed  $queueOlder  receives the day the recent window starts
     */
    private function backfillHistoricalBalances(Carbon $startDate, callable $generateRecent, callable $queueOlder): void
    {
        $twelveMonthsAgo = Carbon::today()->subMonths(12)->startOfMonth();

        $generateRecent($twelveMonthsAgo);

        if ($startDate->isBefore($twelveMonthsAgo)) {
            $queueOlder($twelveMonthsAgo->copy()->subDay());
        }
    }

    /**
     * Point the property this mortgage belongs to back at the loan account, so the
     * two show up together on the property's chart.
     */
    private function linkToRealEstateAccount(User $user, Account $loanAccount, ?string $realEstateAccountId): void
    {
        if ($realEstateAccountId === null) {
            return;
        }

        $user->accounts()
            ->whereKey($realEstateAccountId)
            ->where('type', AccountType::RealEstate->value)
            ->with('realEstateDetail')
            ->first()
            ?->realEstateDetail
            ?->update(['linked_loan_account_id' => $loanAccount->id]);
    }

    /**
     * Update the loan's details, or create them when the account did not have any
     * yet.
     *
     * @param  array<string, mixed>  $data
     * @return list<string> the fields still needed to create a loan detail
     */
    private function syncLoanDetail(Account $account, array $data): array
    {
        $loanData = $this->loanAttributes($data);

        if ($loanData === []) {
            return [];
        }

        $existingLoanDetail = $account->loanDetail;

        if ($existingLoanDetail !== null) {
            $existingLoanDetail->update($loanData);

            return [];
        }

        if (isset($loanData['annual_interest_rate'], $loanData['loan_term_months'], $loanData['original_amount'])) {
            $loanData['start_date'] ??= now()->toDateString();
            $account->loanDetail()->create($loanData);

            return [];
        }

        return array_values(array_filter(
            ['annual_interest_rate', 'loan_term_months', 'original_amount'],
            fn (string $field): bool => ! isset($loanData[$field]),
        ));
    }
}

<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccountType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccountRequest;
use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Jobs\GenerateHistoricalLoanBalancesJob;
use App\Jobs\GenerateHistoricalRealEstateBalancesJob;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\LoanBalanceGeneratorService;
use App\Services\RealEstateBalanceGeneratorService;
use Carbon\Carbon;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's accounts settings page.
     */
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        // This is the one page archived accounts still show up on, so they can be
        // brought back; they sort below the live ones instead of in among them.
        $accounts = $user
            ->accounts()
            ->with(['bank', 'loanDetail', 'realEstateDetail'])
            ->orderBy('archived')
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/accounts', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Store a newly created account.
     */
    public function store(StoreAccountRequest $request, RealEstateBalanceGeneratorService $balanceGenerator, LoanBalanceGeneratorService $loanBalanceGenerator, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $validated = $request->validated();
        $balance = $validated['balance'] ?? null;

        $account = $user->accounts()->create($this->accountAttributes($validated));

        if ($balance !== null) {
            $account->balances()->create([
                'balance_date' => now()->toDateString(),
                'balance' => $balance,
            ]);
        }

        if ($account->type === AccountType::RealEstate) {
            $this->createRealEstateDetail($account, $validated, $balance, $balanceGenerator);
        }

        if ($account->type === AccountType::Loan) {
            $this->createLoanDetail($account, $validated, $balance, $loanBalanceGenerator);
            $this->linkToRealEstateAccount($user, $account, $validated['linked_real_estate_account_id'] ?? null);
        }

        $accountUserCurrencyService->syncFromFirstAccount($account);

        if ($request->wantsJson()) {
            return response()->json($account, 201);
        }

        return redirect(url()->previousPath());
    }

    /**
     * Update the specified account.
     */
    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validated();

        $account->update($this->accountAttributes($validated));

        if ($account->type === AccountType::RealEstate) {
            $realEstateData = $this->realEstateAttributes($validated);

            if ($realEstateData !== []) {
                $account->realEstateDetail()->updateOrCreate(
                    ['account_id' => $account->id],
                    $realEstateData,
                );
            }
        }

        if ($account->type === AccountType::Loan) {
            $errors = $this->syncLoanDetail($account, $validated);

            if ($errors !== []) {
                return to_route('accounts.index')->withErrors($errors);
            }
        }

        return to_route('accounts.index');
    }

    /**
     * The account's own columns. Encryption is gone, so every write clears the
     * legacy flags rather than leaving stale ones behind.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function accountAttributes(array $validated): array
    {
        return [
            ...collect($validated)->only([
                'name', 'bank_id', 'currency_code', 'type',
                'ownership_percentage', 'ownership_applies_to_balance',
            ])->toArray(),
            'encrypted' => false,
            'name_iv' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function realEstateAttributes(array $validated): array
    {
        return collect($validated)->only([
            'property_type', 'address', 'purchase_price', 'purchase_date',
            'area_value', 'area_unit', 'linked_loan_account_id', 'notes',
            'revaluation_percentage',
        ])->filter(fn ($value) => $value !== null)->toArray();
    }

    /**
     * The loan's own columns, defaulting the start date to what the user sent.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function loanAttributes(array $validated): array
    {
        $loanData = collect($validated)->only([
            'annual_interest_rate', 'loan_term_months', 'original_amount',
        ])->filter(fn ($value) => $value !== null)->toArray();

        $loanStartDate = $validated['loan_start_date'] ?? null;

        if ($loanStartDate) {
            $loanData['start_date'] = $loanStartDate;
        }

        return $loanData;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createRealEstateDetail(Account $account, array $validated, ?int $balance, RealEstateBalanceGeneratorService $balanceGenerator): void
    {
        $realEstateData = $this->realEstateAttributes($validated);

        if ($realEstateData !== []) {
            $account->realEstateDetail()->create($realEstateData);
        }

        // Historical balances need both ends of the line: what it was bought for
        // and what it is worth now.
        if ($balance === null || ! isset($validated['purchase_price'], $validated['purchase_date'])) {
            return;
        }

        $this->backfillHistoricalBalances(
            Carbon::parse($validated['purchase_date']),
            fn (Carbon $from) => $balanceGenerator->generateHistoricalBalances(
                $account,
                $validated['purchase_price'],
                Carbon::parse($validated['purchase_date']),
                $balance,
                from: $from,
            ),
            fn (Carbon $until) => GenerateHistoricalRealEstateBalancesJob::dispatch(
                $account,
                $validated['purchase_price'],
                Carbon::parse($validated['purchase_date']),
                $balance,
                Carbon::parse($validated['purchase_date']),
                $until,
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function createLoanDetail(Account $account, array $validated, ?int $balance, LoanBalanceGeneratorService $loanBalanceGenerator): void
    {
        $loanData = $this->loanAttributes($validated);

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
            fn (Carbon $from) => $loanBalanceGenerator->generateHistoricalBalances(
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
     * yet. Returns the fields still missing when there is not enough to create a
     * loan with, so the caller can send them back to the form.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, string>
     */
    private function syncLoanDetail(Account $account, array $validated): array
    {
        $loanData = $this->loanAttributes($validated);

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

        $errors = [];

        foreach (['annual_interest_rate', 'loan_term_months', 'original_amount'] as $field) {
            if (! isset($loanData[$field])) {
                $errors[$field] = __('This field is required.');
            }
        }

        return $errors;
    }

    /**
     * Hard delete the specified account and cascade delete all transactions.
     */
    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $account->transactions()->delete();
        $account->delete();

        return to_route('accounts.index');
    }
}

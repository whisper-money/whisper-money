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

        $accounts = $user
            ->accounts()
            ->with(['bank', 'loanDetail', 'realEstateDetail'])
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

        $accountData = collect($validated)->only([
            'name', 'bank_id', 'currency_code', 'type',
        ])->toArray();

        $account = $user->accounts()->create([
            ...$accountData,
            'encrypted' => false,
            'name_iv' => null,
        ]);

        if ($balance !== null) {
            $account->balances()->create([
                'balance_date' => now()->toDateString(),
                'balance' => $balance,
            ]);
        }

        // Create real estate detail if account type is real_estate
        if ($account->type === AccountType::RealEstate) {
            $realEstateData = collect($validated)->only([
                'property_type', 'address', 'purchase_price', 'purchase_date',
                'area_value', 'area_unit', 'linked_loan_account_id', 'notes',
                'revaluation_percentage',
            ])->filter(fn ($value) => $value !== null)->toArray();

            if (! empty($realEstateData)) {
                $account->realEstateDetail()->create($realEstateData);
            }

            // Generate historical balances when purchase data and current value are provided
            if ($balance !== null && isset($validated['purchase_price'], $validated['purchase_date'])) {
                $purchaseDate = Carbon::parse($validated['purchase_date']);
                $twelveMonthsAgo = Carbon::today()->subMonths(12)->startOfMonth();

                // Generate the last 12 months synchronously
                $balanceGenerator->generateHistoricalBalances(
                    $account,
                    $validated['purchase_price'],
                    $purchaseDate,
                    $balance,
                    from: $twelveMonthsAgo,
                );

                // Dispatch older balances asynchronously if the purchase predates the sync window
                if ($purchaseDate->isBefore($twelveMonthsAgo)) {
                    GenerateHistoricalRealEstateBalancesJob::dispatch(
                        $account,
                        $validated['purchase_price'],
                        $purchaseDate,
                        $balance,
                        $purchaseDate,
                        $twelveMonthsAgo->copy()->subDay(),
                    );
                }
            }
        }

        // Create loan detail if account type is loan and loan fields are provided
        if ($account->type === AccountType::Loan) {
            $loanData = collect($validated)->only([
                'annual_interest_rate', 'loan_term_months', 'original_amount',
            ])->filter(fn ($value) => $value !== null)->toArray();

            $loanStartDate = $validated['loan_start_date'] ?? null;
            if ($loanStartDate) {
                $loanData['start_date'] = $loanStartDate;
            }

            if (! empty($loanData) && isset($loanData['annual_interest_rate'], $loanData['loan_term_months'], $loanData['original_amount'])) {
                if (! isset($loanData['start_date'])) {
                    $loanData['start_date'] = now()->toDateString();
                }

                $loanDetail = $account->loanDetail()->create($loanData);

                if ($balance !== null) {
                    $startDate = Carbon::parse($loanDetail->start_date);
                    $twelveMonthsAgo = Carbon::today()->subMonths(12)->startOfMonth();

                    $loanBalanceGenerator->generateHistoricalBalances(
                        $account,
                        (int) $loanDetail->original_amount,
                        $startDate,
                        $balance,
                        from: $twelveMonthsAgo,
                    );

                    if ($startDate->isBefore($twelveMonthsAgo)) {
                        GenerateHistoricalLoanBalancesJob::dispatch(
                            $account,
                            (int) $loanDetail->original_amount,
                            $startDate,
                            $balance,
                            $startDate,
                            $twelveMonthsAgo->copy()->subDay(),
                        );
                    }
                }
            }

            $linkedRealEstateAccountId = $validated['linked_real_estate_account_id'] ?? null;

            if ($linkedRealEstateAccountId !== null) {
                $realEstateAccount = $user->accounts()
                    ->whereKey($linkedRealEstateAccountId)
                    ->where('type', AccountType::RealEstate->value)
                    ->with('realEstateDetail')
                    ->first();

                $realEstateAccount?->realEstateDetail?->update([
                    'linked_loan_account_id' => $account->id,
                ]);
            }
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

        $accountData = collect($validated)->only([
            'name', 'bank_id', 'currency_code', 'type',
            'ownership_percentage', 'ownership_applies_to_balance',
        ])->toArray();

        $account->update([
            ...$accountData,
            'encrypted' => false,
            'name_iv' => null,
        ]);

        // Update or create real estate detail if account type is real_estate
        if ($account->type === AccountType::RealEstate) {
            $realEstateData = collect($validated)->only([
                'property_type', 'address', 'purchase_price', 'purchase_date',
                'area_value', 'area_unit', 'linked_loan_account_id', 'notes',
                'revaluation_percentage',
            ])->filter(fn ($value) => $value !== null)->toArray();

            if (! empty($realEstateData)) {
                $account->realEstateDetail()->updateOrCreate(
                    ['account_id' => $account->id],
                    $realEstateData,
                );
            }
        }

        if ($account->type === AccountType::Loan) {
            $incompleteLoan = $this->syncLoanDetail($account, $validated);

            if ($incompleteLoan !== null) {
                return $incompleteLoan;
            }
        }

        return to_route('accounts.index');
    }

    /**
     * Update or create the account's loan detail from the validated payload.
     *
     * Returns a redirect carrying the missing-field errors when a loan detail
     * has to be created but the payload is incomplete, and null otherwise.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncLoanDetail(Account $account, array $validated): ?RedirectResponse
    {
        $loanData = collect($validated)->only([
            'annual_interest_rate', 'loan_term_months', 'original_amount',
        ])->filter(fn ($value) => $value !== null)->toArray();

        $loanStartDate = $validated['loan_start_date'] ?? null;
        if ($loanStartDate) {
            $loanData['start_date'] = $loanStartDate;
        }

        if (empty($loanData)) {
            return null;
        }

        $existingLoanDetail = $account->loanDetail;

        if ($existingLoanDetail) {
            $existingLoanDetail->update($loanData);

            return null;
        }

        if (isset($loanData['annual_interest_rate'], $loanData['loan_term_months'], $loanData['original_amount'])) {
            $loanData['start_date'] ??= now()->toDateString();

            $account->loanDetail()->create($loanData);

            return null;
        }

        $required = ['annual_interest_rate', 'loan_term_months', 'original_amount'];
        $errors = [];

        foreach ($required as $field) {
            if (! isset($loanData[$field])) {
                $errors[$field] = __('This field is required.');
            }
        }

        return to_route('accounts.index')->withErrors($errors);
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

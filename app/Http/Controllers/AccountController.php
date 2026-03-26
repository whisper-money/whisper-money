<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Services\AccountMetricsService;
use App\Services\LoanAmortizationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private AccountMetricsService $accountMetricsService,
        private LoanAmortizationService $loanAmortizationService,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with(['bank:id,name,logo', 'realEstateDetail:account_id,linked_loan_account_id'])
            ->orderByRaw("FIELD(type, 'checking', 'savings', 'investment', 'retirement', 'real_estate', 'loan', 'credit_card', 'others')")
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        $accountsData = $accounts->map(function (Account $account) {
            $data = $account->only(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id', 'bank']);

            if ($account->type === AccountType::RealEstate && $account->realEstateDetail?->linked_loan_account_id) {
                $data['linked_loan_account_id'] = $account->realEstateDetail->linked_loan_account_id;
            }

            return $data;
        });

        return Inertia::render('Accounts/Index', [
            'accounts' => $accountsData,
            'accountMetrics' => Inertia::defer(fn () => $this->accountMetricsService->getAccountMetrics($user->currency_code, $accounts)),
        ]);
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $account->load('bank:id,name,logo');

        $data = $account->only(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id', 'bank']);

        if ($account->type === AccountType::RealEstate) {
            $account->load('realEstateDetail.linkedLoanAccount.bank:id,name,logo');
            $realEstateDetail = $account->realEstateDetail;

            if ($realEstateDetail) {
                $linkedLoan = $realEstateDetail->linkedLoanAccount;

                $data['real_estate_detail'] = [
                    ...$realEstateDetail->only([
                        'id', 'property_type', 'address', 'purchase_price',
                        'area_value', 'area_unit', 'notes',
                        'revaluation_percentage', 'linked_loan_account_id',
                    ]),
                    'purchase_date' => $realEstateDetail->purchase_date?->format('Y-m-d'),
                    'linked_loan_account' => $linkedLoan
                        ? $linkedLoan->only(['id', 'name', 'name_iv', 'encrypted', 'type', 'currency_code', 'bank'])
                        : null,
                ];

                // Include current balances for equity calculation
                if ($linkedLoan) {
                    $data['real_estate_detail']['current_loan_balance'] = AccountBalance::query()
                        ->where('account_id', $linkedLoan->id)
                        ->where('balance_date', '<=', now()->toDateString())
                        ->orderByDesc('balance_date')
                        ->value('balance') ?? 0;

                    // Include linked loan account at top level for header actions
                    $data['linked_loan_account'] = $linkedLoan->only(['id', 'name', 'name_iv', 'encrypted', 'type', 'currency_code', 'bank', 'banking_connection_id']);

                    // Load loan amortization details for the linked loan
                    $linkedLoan->load('loanDetail');
                    $loanDetail = $linkedLoan->loanDetail;

                    if ($loanDetail) {
                        $remainingMonths = $this->loanAmortizationService->calculateRemainingMonths($loanDetail, now());

                        $lastLoanBalance = AccountBalance::query()
                            ->where('account_id', $linkedLoan->id)
                            ->orderBy('balance_date', 'desc')
                            ->value('balance');

                        $monthlyPayment = $this->loanAmortizationService->calculateMonthlyPayment(
                            $lastLoanBalance ?? $loanDetail->original_amount,
                            (float) $loanDetail->annual_interest_rate,
                            $lastLoanBalance ? $remainingMonths : $loanDetail->loan_term_months,
                        );

                        $data['loan_detail'] = [
                            ...$loanDetail->only([
                                'id', 'annual_interest_rate', 'loan_term_months',
                                'start_date', 'original_amount',
                            ]),
                            'monthly_payment' => $monthlyPayment,
                            'remaining_months' => $remainingMonths,
                        ];
                    }
                }

                $data['real_estate_detail']['current_market_value'] = AccountBalance::query()
                    ->where('account_id', $account->id)
                    ->where('balance_date', '<=', now()->toDateString())
                    ->orderByDesc('balance_date')
                    ->value('balance') ?? 0;
            }

            // Provide available loan accounts for linking
            $data['available_loan_accounts'] = $request->user()
                ->accounts()
                ->where('type', AccountType::Loan->value)
                ->with('bank:id,name,logo')
                ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']);
        }

        if ($account->type === AccountType::Loan) {
            $account->load('loanDetail');
            $loanDetail = $account->loanDetail;

            if ($loanDetail) {
                $remainingMonths = $this->loanAmortizationService->calculateRemainingMonths($loanDetail, now());

                $lastBalance = AccountBalance::query()
                    ->where('account_id', $account->id)
                    ->orderBy('balance_date', 'desc')
                    ->value('balance');

                $monthlyPayment = $this->loanAmortizationService->calculateMonthlyPayment(
                    $lastBalance ?? $loanDetail->original_amount,
                    (float) $loanDetail->annual_interest_rate,
                    $lastBalance ? $remainingMonths : $loanDetail->loan_term_months,
                );

                $data['loan_detail'] = [
                    ...$loanDetail->only([
                        'id', 'annual_interest_rate', 'loan_term_months',
                        'start_date', 'original_amount',
                    ]),
                    'monthly_payment' => $monthlyPayment,
                    'remaining_months' => $remainingMonths,
                ];
            }
        }

        return Inertia::render('Accounts/Show', [
            'account' => $data,
        ]);
    }
}

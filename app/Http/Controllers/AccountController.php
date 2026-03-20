<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Services\AccountMetricsService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AccountMetricsService $accountMetricsService) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $accounts = Account::query()
            ->where('user_id', $user->id)
            ->with('bank:id,name,logo')
            ->orderByRaw("FIELD(type, 'checking', 'savings', 'investment', 'retirement', 'real_estate', 'loan', 'credit_card', 'others')")
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
            'accountMetrics' => Inertia::defer(fn () => $this->accountMetricsService->getAccountMetrics($user->currency_code, $accounts)),
        ]);
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $account->load('bank:id,name,logo');

        $data = $account->only(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id', 'bank']);

        if ($account->type === \App\Enums\AccountType::RealEstate) {
            $account->load('realEstateDetail.linkedLoanAccount.bank:id,name,logo');
            $realEstateDetail = $account->realEstateDetail;

            if ($realEstateDetail) {
                $data['real_estate_detail'] = [
                    ...$realEstateDetail->only([
                        'id', 'property_type', 'address', 'purchase_price',
                        'purchase_date', 'area_value', 'area_unit', 'notes',
                        'linked_loan_account_id',
                    ]),
                    'linked_loan_account' => $realEstateDetail->linkedLoanAccount
                        ? $realEstateDetail->linkedLoanAccount->only(['id', 'name', 'name_iv', 'encrypted', 'type', 'currency_code', 'bank'])
                        : null,
                ];
            }

            // Provide available loan accounts for linking
            $data['available_loan_accounts'] = $request->user()
                ->accounts()
                ->where('type', \App\Enums\AccountType::Loan->value)
                ->with('bank:id,name,logo')
                ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code']);
        }

        return Inertia::render('Accounts/Show', [
            'account' => $data,
        ]);
    }
}

<?php

namespace App\Http\Controllers;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\AccountType;
use App\Http\Requests\ArchiveAccountRequest;
use App\Http\Requests\ReorderAccountsRequest;
use App\Http\Requests\UpdateAccountVisibilityRequest;
use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\BankingConnection;
use App\Models\LoanDetail;
use App\Models\Transaction;
use App\Services\AccountMetricsService;
use App\Services\LoanAmortizationService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            ->notArchived()
            ->with(['bank', 'realEstateDetail:id,account_id,linked_loan_account_id'])
            ->orderBy('position')
            ->orderBy('name')
            ->get();

        // The real estate detail is loaded only to feed the linked_loan_account_id
        // accessor; it should not be serialized as a nested relation here.
        $accounts->makeHidden('realEstateDetail');

        return Inertia::render('Accounts/Index', [
            'accounts' => $accounts,
            'accountMetrics' => Inertia::defer(fn () => $this->accountMetricsService->getAccountMetrics($user->currency_code, $accounts)),
        ]);
    }

    public function reorder(ReorderAccountsRequest $request): RedirectResponse
    {
        // ponytail: one update per account; fine for the handful of accounts a
        // user has. Switch to a single CASE update if that ever grows large.
        foreach (array_values($request->validated('ids')) as $position => $id) {
            Account::query()
                ->whereKey($id)
                ->where('user_id', $request->user()->id)
                ->update(['position' => $position]);
        }

        return back();
    }

    public function updateVisibility(UpdateAccountVisibilityRequest $request, Account $account): RedirectResponse
    {
        $account->update(['hidden_on_dashboard' => $request->validated('hidden')]);

        return back();
    }

    /**
     * Archiving records the day it happened so the account stops counting from
     * then on without touching the history it already has; unarchiving clears
     * the date and the account goes back to counting.
     *
     * A connected account also stops syncing: sync runs off the connection's
     * accounts, so detaching it is what ends the new transactions and balances
     * the dialog warns about. Reconnecting means going through the bank again,
     * which is why unarchiving cannot undo this half.
     */
    public function updateArchived(
        ArchiveAccountRequest $request,
        Account $account,
        DisconnectBankingConnection $disconnectBankingConnection,
    ): RedirectResponse {
        $archiving = $request->boolean('archived');

        $orphanedConnection = DB::transaction(function () use ($account, $archiving): ?BankingConnection {
            $account->update([
                'archived_at' => $archiving ? now() : null,
            ]);

            return $archiving ? $this->detachFromBank($account) : null;
        });

        // Revoking talks to the provider, so it stays outside the transaction:
        // a slow bank would otherwise hold the connection row locked.
        if ($orphanedConnection) {
            $disconnectBankingConnection->handle($orphanedConnection);
        }

        return back();
    }

    /**
     * Returns the connection only when this was the last account hanging off it,
     * so it can be revoked — the other accounts of the same bank must keep
     * syncing. The row is locked because two accounts archived at once would
     * otherwise both see it as empty and revoke it twice.
     */
    private function detachFromBank(Account $account): ?BankingConnection
    {
        if (! $account->isConnected()) {
            return null;
        }

        $connection = $account->bankingConnection()->lockForUpdate()->first();

        $account->update([
            'banking_connection_id' => null,
            'external_account_id' => null,
        ]);

        return $connection && $connection->accounts()->doesntExist() ? $connection : null;
    }

    public function show(Request $request, Account $account): Response
    {
        $this->authorize('view', $account);

        $account->load('bank');

        $data = $account->toArray();

        if ($account->type === AccountType::RealEstate) {
            $account->load('realEstateDetail.linkedLoanAccount.bank');
            $realEstateDetail = $account->realEstateDetail;

            if ($realEstateDetail) {
                $linkedLoan = $realEstateDetail->linkedLoanAccount;

                $data['real_estate_detail'] = [
                    ...$realEstateDetail->toArray(),
                    'linked_loan_account' => $linkedLoan?->toArray(),
                ];

                // Include current balances for equity calculation
                if ($linkedLoan) {
                    $data['real_estate_detail']['current_loan_balance'] = $this->latestBalance($linkedLoan->id);

                    // Include linked loan account at top level for header actions
                    $data['linked_loan_account'] = $linkedLoan->toArray();

                    $linkedLoan->load('loanDetail');

                    if ($linkedLoan->loanDetail) {
                        $data['loan_detail'] = $this->loanDetailData($linkedLoan->loanDetail, $linkedLoan);
                    }
                }

                $data['real_estate_detail']['current_market_value'] = $this->latestBalance($account->id);
            }

            // Provide available loan accounts for linking
            $data['available_loan_accounts'] = $request->user()
                ->accounts()
                ->notArchived()
                ->where('type', AccountType::Loan->value)
                ->with('bank')
                ->get();
        }

        if ($account->type === AccountType::Loan) {
            $account->load('loanDetail');

            if ($account->loanDetail) {
                $data['loan_detail'] = $this->loanDetailData($account->loanDetail, $account);
            }
        }

        return Inertia::render('Accounts/Show', [
            'account' => $data,
            // Deferred so the page shell paints without blocking on the ledger
            // query/serialization. It stays the whole set because search and
            // filtering run client-side over decrypted rows. ponytail: window it
            // server-side only if one account's history gets big enough that the
            // transfer itself hurts.
            'transactions' => $account->type->hasTransactionLedger()
                ? Inertia::defer(fn () => tap(
                    $account->transactions()
                        ->with(['category', 'labels'])
                        ->orderBy('transaction_date', 'desc')
                        ->orderBy('id', 'desc')
                        ->get(),
                    fn ($transactions) => Transaction::loadSplitSiblings($transactions)
                ))
                : [],
        ]);
    }

    /**
     * Build the loan detail payload, augmenting the model with the computed
     * amortization figures that depend on the account's latest balance.
     *
     * @return array<string, mixed>
     */
    private function loanDetailData(LoanDetail $loanDetail, Account $account): array
    {
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

        return [
            ...$loanDetail->toArray(),
            'monthly_payment' => $monthlyPayment,
            'remaining_months' => $remainingMonths,
        ];
    }

    /**
     * The most recent balance for an account on or before today.
     */
    private function latestBalance(string $accountId): int
    {
        return AccountBalance::query()
            ->where('account_id', $accountId)
            ->where('balance_date', '<=', now()->toDateString())
            ->orderByDesc('balance_date')
            ->value('balance') ?? 0;
    }
}

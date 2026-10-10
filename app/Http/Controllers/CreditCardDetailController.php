<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\UpdateCreditCardDetailRequest;
use App\Models\Account;
use App\Services\AccountWriteService;
use App\Services\CreditCards\CreditCardStatementService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CreditCardDetailController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private AccountWriteService $accountWriteService,
        private CreditCardStatementService $creditCardStatementService,
    ) {}

    /**
     * Set the statement dates of a credit card, which become the new anchor
     * every later cycle is projected from.
     */
    public function update(UpdateCreditCardDetailRequest $request, Account $account): RedirectResponse
    {
        $this->ensureCreditCardStatements($request, $account);

        $this->accountWriteService->syncCreditCardDetail($account, $request->validated());

        return to_route('accounts.show', $account);
    }

    /**
     * Forget the statement dates, which takes the card back to the empty state.
     */
    public function destroy(Request $request, Account $account): RedirectResponse
    {
        $this->ensureCreditCardStatements($request, $account);

        $this->accountWriteService->syncCreditCardDetail($account, [
            'statement_closing_date' => null,
            'payment_due_date' => null,
        ]);

        return to_route('accounts.show', $account);
    }

    private function ensureCreditCardStatements(Request $request, Account $account): void
    {
        abort_unless($this->creditCardStatementService->isAvailableTo($request->user()), 404);

        $this->authorize('update', $account);

        abort_unless($account->type === AccountType::CreditCard, 404);
    }
}

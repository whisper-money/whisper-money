<?php

namespace App\Http\Controllers;

use App\Enums\AccountType;
use App\Http\Requests\UpdateCreditCardDetailRequest;
use App\Models\Account;
use App\Services\AccountWriteService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class CreditCardDetailController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private AccountWriteService $accountWriteService) {}

    /**
     * Set a credit card's limit and statement dates. The dates become the new
     * anchor every later cycle is projected from.
     */
    public function update(UpdateCreditCardDetailRequest $request, Account $account): RedirectResponse
    {
        $this->ensureCreditCard($account);

        $this->accountWriteService->syncCreditCardDetail($account, $request->validated());

        return to_route('accounts.show', $account);
    }

    /**
     * Forget the statement dates, which takes the estimate back to the empty
     * state. The credit limit stays.
     */
    public function destroy(Account $account): RedirectResponse
    {
        $this->ensureCreditCard($account);

        $this->accountWriteService->syncCreditCardDetail($account, [
            'statement_closing_date' => null,
            'payment_due_date' => null,
        ]);

        return to_route('accounts.show', $account);
    }

    private function ensureCreditCard(Account $account): void
    {
        $this->authorize('update', $account);

        abort_unless($account->type === AccountType::CreditCard, 404);
    }
}

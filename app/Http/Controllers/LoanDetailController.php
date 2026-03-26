<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateLoanDetailRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class LoanDetailController extends Controller
{
    use AuthorizesRequests;

    /**
     * Update the loan detail for an account.
     */
    public function update(UpdateLoanDetailRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $loanDetail = $account->loanDetail;

        if (! $loanDetail) {
            // Create if it doesn't exist yet
            $account->loanDetail()->create($request->validated());

            return to_route('accounts.show', $account);
        }

        $loanDetail->update($request->validated());

        return to_route('accounts.show', $account);
    }
}

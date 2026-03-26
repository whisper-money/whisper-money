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
        $data = $request->validated();

        if (! $loanDetail) {
            if (! isset($data['annual_interest_rate'], $data['loan_term_months'], $data['start_date'], $data['original_amount'])) {
                return to_route('accounts.show', $account)
                    ->withErrors(['loan_detail' => 'All loan detail fields are required when creating a new loan detail.']);
            }

            $account->loanDetail()->create($data);

            return to_route('accounts.show', $account);
        }

        $loanDetail->update($data);

        return to_route('accounts.show', $account);
    }
}

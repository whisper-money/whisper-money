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
            $required = ['annual_interest_rate', 'loan_term_months', 'start_date', 'original_amount'];
            $missing = array_filter($required, fn (string $field): bool => ! isset($data[$field]));

            if (! empty($missing)) {
                $errors = [];
                foreach ($missing as $field) {
                    $errors[$field] = __('This field is required.');
                }

                return to_route('accounts.show', $account)->withErrors($errors);
            }

            $account->loanDetail()->create($data);

            return to_route('accounts.show', $account);
        }

        $loanDetail->update($data);

        return to_route('accounts.show', $account);
    }
}

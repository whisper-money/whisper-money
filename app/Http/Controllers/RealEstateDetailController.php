<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateRealEstateDetailRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;

class RealEstateDetailController extends Controller
{
    use AuthorizesRequests;

    /**
     * Update the real estate detail for an account.
     */
    public function update(UpdateRealEstateDetailRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $realEstateDetail = $account->realEstateDetail;

        if (! $realEstateDetail) {
            abort(404);
        }

        $realEstateDetail->update($request->validated());

        return to_route('accounts.show', $account);
    }
}

<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCurrentAccountBalanceRequest;
use App\Models\Account;
use App\Models\AccountBalance;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AccountBalanceController extends Controller
{
    use AuthorizesRequests;

    /**
     * Update or create the current balance for an account.
     */
    public function updateCurrent(UpdateCurrentAccountBalanceRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $today = now()->toDateString();

        $balance = AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $today,
            ],
            [
                'balance' => $request->validated()['balance'],
            ]
        );

        return response()->json([
            'data' => $balance,
        ]);
    }
}

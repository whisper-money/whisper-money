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
     * List paginated balances for an account.
     */
    public function index(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $balances = $account->balances()
            ->orderBy('balance_date', 'desc')
            ->paginate(50);

        return response()->json($balances);
    }

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

    /**
     * Delete a balance record.
     */
    public function destroy(Account $account, AccountBalance $accountBalance): JsonResponse
    {
        $this->authorize('update', $account);

        if ($accountBalance->account_id !== $account->id) {
            abort(404);
        }

        $accountBalance->delete();

        return response()->json(null, 204);
    }
}

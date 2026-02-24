<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAccountBalanceRequest;
use App\Http\Requests\UpdateCurrentAccountBalanceRequest;
use App\Models\Account;
use App\Models\AccountBalance;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;

class AccountBalanceController extends Controller
{
    use AuthorizesRequests;

    public function index(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        $balances = $account->balances()
            ->orderBy('balance_date', 'desc')
            ->paginate(50);

        return response()->json($balances);
    }

    public function store(StoreAccountBalanceRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validated();

        $balance = AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $validated['balance_date'],
            ],
            [
                'balance' => $validated['balance'],
                ...array_key_exists('invested_amount', $validated)
                    ? ['invested_amount' => $validated['invested_amount']]
                    : [],
            ]
        );

        return response()->json([
            'data' => $balance,
        ], 201);
    }

    public function updateCurrent(UpdateCurrentAccountBalanceRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $today = now()->toDateString();
        $validated = $request->validated();

        $balance = AccountBalance::updateOrCreate(
            [
                'account_id' => $account->id,
                'balance_date' => $today,
            ],
            [
                'balance' => $validated['balance'],
                ...array_key_exists('invested_amount', $validated)
                    ? ['invested_amount' => $validated['invested_amount']]
                    : [],
            ]
        );

        return response()->json([
            'data' => $balance,
        ]);
    }

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

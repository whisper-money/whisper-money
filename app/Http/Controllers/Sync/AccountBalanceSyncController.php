<?php

namespace App\Http\Controllers\Sync;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAccountBalanceRequest;
use App\Models\AccountBalance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountBalanceSyncController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = AccountBalance::query()
            ->whereHas('account', function ($q) use ($request) {
                $q->where('user_id', $request->user()->id);
            });

        if ($request->has('since')) {
            $query->where('updated_at', '>', $request->input('since'));
        }

        $balances = $query
            ->orderBy('balance_date', 'desc')
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'data' => $balances,
        ]);
    }

    public function store(StoreAccountBalanceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $balance = new AccountBalance([
            'account_id' => $data['account_id'],
            'balance_date' => $data['balance_date'],
            'balance' => $data['balance'],
        ]);

        if (isset($data['id'])) {
            $balance->id = $data['id'];
            $balance->exists = false;
        }

        $balance->save();

        return response()->json([
            'data' => $balance,
        ], 201);
    }

    public function update(StoreAccountBalanceRequest $request, AccountBalance $accountBalance): JsonResponse
    {
        $accountBalance->account->loadMissing('user');

        if ($accountBalance->account->user_id !== $request->user()->id) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validated();
        unset($data['id']);

        $accountBalance->update($data);

        return response()->json([
            'data' => $accountBalance->fresh(),
        ]);
    }
}

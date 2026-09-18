<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\UpdateAccountNameRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    use AuthorizesRequests;

    /**
     * Return all accounts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        // Account hides bank_id by default; opt it back in explicitly here.
        $accounts = $request->user()
            ->accounts()
            ->get()
            ->makeVisible('bank_id');

        return response()->json($accounts);
    }

    /**
     * Update an account's name.
     */
    public function update(UpdateAccountNameRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validated();

        $account->update(['name' => $validated['name']]);

        return response()->json($account);
    }
}

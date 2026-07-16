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
        // The decryption-migration flow needs bank_id, which Account hides by
        // default; opt it back in explicitly here.
        $space = $request->user()->activeSpace();

        $accounts = $space
            ->accounts()
            ->get()
            ->makeVisible('bank_id');

        return response()->json($accounts);
    }

    /**
     * Update an account's name (used for decryption migration).
     */
    public function update(UpdateAccountNameRequest $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $validated = $request->validated();

        $account->update([
            'name' => $validated['name'],
            'encrypted' => $validated['encrypted'],
            'name_iv' => $validated['encrypted'] ? $account->name_iv : null,
        ]);

        return response()->json($account);
    }
}

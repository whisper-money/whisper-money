<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccountRequest;
use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $accounts = $request->user()
            ->accounts()
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

        return Inertia::render('settings/accounts', [
            'accounts' => $accounts,
        ]);
    }

    public function store(StoreAccountRequest $request): RedirectResponse|JsonResponse
    {
        $user = $request->user();
        $account = $user->accounts()->create([
            ...$request->validated(),
            'encrypted' => false,
            'name_iv' => null,
        ]);

        // Set user's currency_code from first account
        if ($user->accounts()->count() === 1) {
            $user->update(['currency_code' => $account->currency_code]);
        }

        if ($request->wantsJson()) {
            return response()->json($account, 201);
        }

        return to_route('accounts.index');
    }

    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $account->update([
            ...$request->validated(),
            'encrypted' => false,
            'name_iv' => null,
        ]);

        return to_route('accounts.index');
    }

    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $account->transactions()->delete();
        $account->delete();

        return to_route('accounts.index');
    }
}

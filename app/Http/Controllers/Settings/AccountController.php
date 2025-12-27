<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccountRequest;
use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Models\Account;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's accounts settings page.
     */
    public function index(): Response
    {
        $accounts = auth()->user()
            ->accounts()
            ->with('bank:id,name,logo')
            ->orderBy('name')
            ->get(['id', 'name', 'name_iv', 'bank_id', 'type', 'currency_code']);

        return Inertia::render('settings/accounts', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Store a newly created account.
     */
    public function store(StoreAccountRequest $request): RedirectResponse|JsonResponse
    {
        $user = auth()->user();
        $account = $user->accounts()->create($request->validated());

        // Set user's currency_code from first account if not already set
        if (is_null($user->currency_code)) {
            $user->update(['currency_code' => $account->currency_code]);
        }

        if ($request->wantsJson()) {
            return response()->json($account, 201);
        }

        return to_route('accounts.index');
    }

    /**
     * Update the specified account.
     */
    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        $this->authorize('update', $account);

        $account->update($request->validated());

        return to_route('accounts.index');
    }

    /**
     * Hard delete the specified account and cascade delete all transactions.
     */
    public function destroy(Account $account): RedirectResponse
    {
        $this->authorize('delete', $account);

        $account->transactions()->delete();
        $account->delete();

        return to_route('accounts.index');
    }
}

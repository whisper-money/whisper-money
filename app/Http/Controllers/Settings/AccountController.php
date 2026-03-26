<?php

namespace App\Http\Controllers\Settings;

use App\Enums\AccountType;
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
            ->get(['id', 'name', 'name_iv', 'encrypted', 'bank_id', 'type', 'currency_code', 'banking_connection_id']);

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
        $validated = $request->validated();
        $balance = $validated['balance'] ?? null;

        $accountData = collect($validated)->only([
            'name', 'bank_id', 'currency_code', 'type',
        ])->toArray();

        $account = $user->accounts()->create([
            ...$accountData,
            'encrypted' => false,
            'name_iv' => null,
        ]);

        if ($balance !== null) {
            $account->balances()->create([
                'balance_date' => now()->toDateString(),
                'balance' => $balance,
            ]);
        }

        // Create real estate detail if account type is real_estate
        if ($account->type === AccountType::RealEstate) {
            $realEstateData = collect($validated)->only([
                'property_type', 'address', 'purchase_price', 'purchase_date',
                'area_value', 'area_unit', 'linked_loan_account_id', 'notes',
                'revaluation_percentage',
            ])->filter(fn ($value) => $value !== null)->toArray();

            if (! empty($realEstateData)) {
                $account->realEstateDetail()->create($realEstateData);
            }
        }

        // Set user's currency_code from first account
        if ($user->accounts()->count() === 1) {
            $user->update(['currency_code' => $account->currency_code]);
        }

        if ($request->wantsJson()) {
            return response()->json($account, 201);
        }

        return back();
    }

    /**
     * Update the specified account.
     */
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

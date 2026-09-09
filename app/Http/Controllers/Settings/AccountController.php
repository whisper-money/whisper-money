<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\StoreAccountRequest;
use App\Http\Requests\Settings\UpdateAccountRequest;
use App\Models\Account;
use App\Models\User;
use App\Services\AccountWriteService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
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
        /** @var User $user */
        $user = Auth::user();

        // This is the one page archived accounts still show up on, so they can be
        // brought back; a null archived_at sorts first, putting them below the
        // live ones instead of in among them.
        $accounts = $user
            ->accounts()
            ->with(['bank', 'loanDetail', 'realEstateDetail'])
            ->orderBy('archived_at')
            ->orderBy('name')
            ->get();

        return Inertia::render('settings/accounts', [
            'accounts' => $accounts,
        ]);
    }

    /**
     * Store a newly created account.
     */
    public function store(StoreAccountRequest $request, AccountWriteService $accountWriter): RedirectResponse|JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();

        $account = $accountWriter->create($user, $request->validated());

        if ($request->wantsJson()) {
            return response()->json($account, 201);
        }

        return redirect(url()->previousPath());
    }

    /**
     * Update the specified account.
     */
    public function update(UpdateAccountRequest $request, Account $account, AccountWriteService $accountWriter): RedirectResponse
    {
        $this->authorize('update', $account);

        $missingLoanFields = $accountWriter->update($account, $request->validated());

        if ($missingLoanFields !== []) {
            return to_route('accounts.index')->withErrors(
                array_fill_keys($missingLoanFields, __('This field is required.')),
            );
        }

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

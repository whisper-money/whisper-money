<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingSyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Requests\OpenBanking\MapAccountsRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Account;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\Formatters\AccountNameFormatter;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountMappingController extends Controller
{
    use CreatesAccountsFromPending;

    public function show(BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): Response|RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        $user = auth()->user();

        if (! $connection->hasPendingAccounts()) {
            return $this->backToAccounts($user);
        }

        $mappableAccounts = $connection->mappablePendingAccounts();

        // Nothing the bank gave us can be mapped, so there is no decision left for the
        // user to make. Close the connection instead of leaving it awaiting a mapping
        // that can never happen.
        if ($mappableAccounts === []) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);

            return $this->backToAccounts($user)
                ->with('error', __('Your bank did not provide an identifier for any of its accounts, so they cannot be synced.'));
        }

        // During onboarding the accounts hub renders the chooser itself, so there
        // is no separate mapping screen to show. It used to take every account the
        // bank offered without asking, which is how a joint account nobody wanted
        // in their first picture ended up in it.
        if (! $user->isOnboarded()) {
            return redirect()->route('onboarding', ['step' => 'create-account']);
        }

        $existingAccounts = $user
            ->accounts()
            ->whereNull('banking_connection_id')
            ->with('bank')
            ->get();

        return Inertia::render('open-banking/map-accounts', [
            'connection' => $connection,
            'bankAccounts' => $mappableAccounts,
            'existingAccounts' => $existingAccounts,
            'unmappableAccountNames' => $connection->unmappablePendingAccountNames(),
        ]);
    }

    public function store(MapAccountsRequest $request, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        $user = auth()->user();
        $mappings = $request->validated()['mappings'];

        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        if (! $bank->logo && $connection->aspsp_logo) {
            $bank->update(['logo' => $connection->aspsp_logo]);
        }

        $pendingAccounts = collect($connection->pending_accounts_data)
            ->keyBy('uid');

        $accountType = $connection->provider->defaultAccountType();

        foreach ($mappings as $mapping) {
            $uid = $mapping['bank_account_uid'];
            $action = $mapping['action'];
            $accountData = $pendingAccounts->get($uid);

            if (! $accountData) {
                continue;
            }

            if ($action === 'create') {
                $currency = $accountUserCurrencyService->resolveImportedCurrency($accountData['currency'] ?? null, $user);
                $name = AccountNameFormatter::format($accountData, $connection->aspsp_name.' Account');

                $account = $user->accounts()->create([
                    'name' => $name,
                    'name_iv' => null,
                    'encrypted' => false,
                    'bank_id' => $bank->id,
                    'currency_code' => $currency,
                    'type' => $accountType->value,
                    'banking_connection_id' => $connection->id,
                    'external_account_id' => $uid,
                    'iban' => $accountData['account_id']['iban'] ?? null,
                ]);

                $accountUserCurrencyService->syncFromFirstAccount($account);
            } elseif ($action === 'link') {
                $this->linkExistingAccount(
                    $user->accounts()->find($mapping['existing_account_id']),
                    $connection,
                    $bank,
                    $uid,
                    $accountData,
                    $accountUserCurrencyService,
                );
            }
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
        ]);

        SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::AccountMapping);

        return $this->backToAccounts($user)
            ->with('success', 'Bank account connected successfully.');
    }

    /**
     * Where a reader belongs once a connection is dealt with: the accounts hub
     * mid-onboarding, the connections screen once they are through it.
     */
    private function backToAccounts(User $user): RedirectResponse
    {
        return $user->isOnboarded()
            ? redirect()->route('settings.connections.index')
            : redirect()->route('onboarding', ['step' => 'create-account']);
    }

    /**
     * Point an account the user already had at the connection that now feeds
     * it. Null when the mapping named an account that is not theirs, which the
     * request has no way to rule out and nothing here needs to react to.
     *
     * @param  array<string, mixed>  $accountData
     */
    private function linkExistingAccount(
        ?Account $account,
        BankingConnection $connection,
        Bank $bank,
        string $uid,
        array $accountData,
        AccountUserCurrencyService $accountUserCurrencyService,
    ): void {
        if (! $account) {
            return;
        }

        $account->update([
            'banking_connection_id' => $connection->id,
            'external_account_id' => $uid,
            'iban' => $accountData['account_id']['iban'] ?? $account->iban,
            'bank_id' => $bank->id,
            'linked_at' => now(),
        ]);

        $accountUserCurrencyService->syncFromFirstAccount($account);
    }
}

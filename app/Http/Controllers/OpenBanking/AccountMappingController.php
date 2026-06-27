<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Requests\OpenBanking\MapAccountsRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Services\AccountUserCurrencyService;
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
            $redirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
            $params = $user->isOnboarded() ? [] : ['step' => 'create-account'];

            return redirect()->route($redirect, $params);
        }

        // During onboarding, skip the mapping UI — auto-create all accounts directly
        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection);

            return redirect()->route('onboarding', ['step' => 'create-account'])
                ->with('success', 'Bank account connected successfully.');
        }

        $existingAccounts = $user
            ->accounts()
            ->whereNull('banking_connection_id')
            ->with('bank')
            ->get();

        return Inertia::render('open-banking/map-accounts', [
            'connection' => $connection,
            'bankAccounts' => $connection->pending_accounts_data,
            'existingAccounts' => $existingAccounts,
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
                $name = $accountData['name']
                    ?? $accountData['account_id']['iban']
                    ?? $connection->aspsp_name.' Account';

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
                $existingAccount = $user->accounts()->find($mapping['existing_account_id']);

                if ($existingAccount) {
                    $existingAccount->update([
                        'banking_connection_id' => $connection->id,
                        'external_account_id' => $uid,
                        'iban' => $accountData['account_id']['iban'] ?? $existingAccount->iban,
                        'bank_id' => $bank->id,
                        'linked_at' => now(),
                    ]);

                    $accountUserCurrencyService->syncFromFirstAccount($existingAccount);
                }
            }
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        $successRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $redirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        return redirect()->route($successRedirect, $redirectParams)
            ->with('success', 'Bank account connected successfully.');
    }
}

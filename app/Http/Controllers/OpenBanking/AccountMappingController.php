<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\MapAccountsRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccountMappingController extends Controller
{
    public function show(BankingConnection $connection): Response|RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if (! $connection->hasPendingAccounts()) {
            return redirect()->route('settings.connections.index');
        }

        $existingAccounts = auth()->user()
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

    public function store(MapAccountsRequest $request, BankingConnection $connection): RedirectResponse
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

        $accountType = ($connection->isIndexaCapital() || $connection->isBinance())
            ? AccountType::Investment
            : AccountType::Checking;

        foreach ($mappings as $mapping) {
            $uid = $mapping['bank_account_uid'];
            $action = $mapping['action'];
            $accountData = $pendingAccounts->get($uid);

            if (! $accountData) {
                continue;
            }

            if ($action === 'create') {
                $currency = $accountData['currency'] ?? 'EUR';
                $name = $accountData['name']
                    ?? $accountData['account_id']['iban']
                    ?? $connection->aspsp_name.' Account';

                $user->accounts()->create([
                    'name' => $name,
                    'name_iv' => null,
                    'encrypted' => false,
                    'bank_id' => $bank->id,
                    'currency_code' => $currency,
                    'type' => $accountType->value,
                    'banking_connection_id' => $connection->id,
                    'external_account_id' => $uid,
                ]);
            } elseif ($action === 'link') {
                $existingAccount = $user->accounts()->find($mapping['existing_account_id']);

                if ($existingAccount) {
                    $existingAccount->update([
                        'banking_connection_id' => $connection->id,
                        'external_account_id' => $uid,
                        'bank_id' => $bank->id,
                        'linked_at' => now(),
                    ]);
                }
            }
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        return redirect()->route('settings.connections.index')
            ->with('success', 'Bank account connected successfully.');
    }
}

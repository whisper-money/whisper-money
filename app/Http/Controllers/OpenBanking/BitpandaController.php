<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\ConnectBitpandaRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\Banking\BitpandaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class BitpandaController extends Controller
{
    /**
     * Validate Bitpanda API key and create a connection.
     */
    public function store(ConnectBitpandaRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        $client = new BitpandaClient($validated['api_key']);

        try {
            $client->getCryptoWallets();
        } catch (\Throwable $e) {
            Log::warning('Bitpanda credential validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid API key or failed to connect to Bitpanda.',
            ], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Bitpanda', 'user_id' => null],
            ['name' => 'Bitpanda', 'logo' => 'https://whisper.money/storage/banks/logos/7Y6gl0gaFH1mStJMcUQ9VpgzX1kduyumm0dDhGlf.png'],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => 'bitpanda',
            'api_token' => $validated['api_key'],
            'aspsp_name' => 'Bitpanda',
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $pendingAccounts = [
            [
                'uid' => 'bitpanda-portfolio',
                'currency' => $user->currency_code,
                'name' => 'Crypto Portfolio',
            ],
        ];

        if (Feature::for($user)->active('account-mapping')) {
            $connection->update([
                'status' => BankingConnectionStatus::AwaitingMapping,
                'pending_accounts_data' => $pendingAccounts,
            ]);

            return response()->json([
                'redirect_url' => route('open-banking.map-accounts', $connection),
                'connection_id' => $connection->id,
            ]);
        }

        $connection->update(['status' => BankingConnectionStatus::Active]);

        $user->accounts()->create([
            'name' => 'Crypto Portfolio',
            'name_iv' => null,
            'encrypted' => false,
            'bank_id' => $bank->id,
            'currency_code' => $user->currency_code,
            'type' => AccountType::Investment->value,
            'banking_connection_id' => $connection->id,
            'external_account_id' => 'bitpanda-portfolio',
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        $successRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $redirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        return response()->json([
            'redirect_url' => route($successRedirect, $redirectParams),
            'connection_id' => $connection->id,
        ]);
    }
}

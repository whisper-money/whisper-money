<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectBitpandaRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\BitpandaClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BitpandaController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate Bitpanda API key and create a connection.
     */
    public function store(ConnectBitpandaRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

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
            'provider' => BankingProvider::Bitpanda,
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

        $connection->update([
            'status' => BankingConnectionStatus::AwaitingMapping,
            'pending_accounts_data' => $pendingAccounts,
        ]);

        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection);

            return response()->json([
                'redirect_url' => route('onboarding', ['step' => 'create-account']),
                'connection_id' => $connection->id,
            ]);
        }

        return response()->json([
            'redirect_url' => route('open-banking.map-accounts', $connection),
            'connection_id' => $connection->id,
        ]);
    }
}

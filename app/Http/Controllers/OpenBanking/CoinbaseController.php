<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectCoinbaseRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\CoinbaseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class CoinbaseController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate Coinbase CDP API credentials and create a connection.
     */
    public function store(ConnectCoinbaseRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $client = new CoinbaseClient($validated['api_key_name'], $validated['private_key']);

        try {
            $client->getAccounts(limit: 1);
        } catch (\Throwable $e) {
            Log::warning('Coinbase credential validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid API credentials or failed to connect to Coinbase.',
            ], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Coinbase', 'user_id' => null],
            ['name' => 'Coinbase', 'logo' => 'https://whisper.money/storage/banks/logos/coinbase.png'],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => BankingProvider::Coinbase,
            'api_token' => $validated['api_key_name'],
            'api_secret' => $validated['private_key'],
            'aspsp_name' => 'Coinbase',
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $pendingAccounts = [
            [
                'uid' => 'coinbase-portfolio',
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

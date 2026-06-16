<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectBinanceRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\BinanceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class BinanceController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate Binance API credentials and create a connection.
     */
    public function store(ConnectBinanceRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $client = new BinanceClient($validated['api_key'], $validated['api_secret']);

        try {
            $client->getAccount();
        } catch (\Throwable $e) {
            Log::warning('Binance credential validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid API credentials or failed to connect to Binance.',
            ], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Binance', 'user_id' => null],
            ['name' => 'Binance', 'logo' => 'https://whisper.money/storage/banks/logos/t1h5rqi19dJTPl6ZadziPjNwm0lrcdTFBRzB3iCy.png'],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => BankingProvider::Binance,
            'api_token' => $validated['api_key'],
            'api_secret' => $validated['api_secret'],
            'aspsp_name' => 'Binance',
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $pendingAccounts = [
            [
                'uid' => 'binance-portfolio',
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

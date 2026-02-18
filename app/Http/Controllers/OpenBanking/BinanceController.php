<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\ConnectBinanceRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\Banking\BinanceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class BinanceController extends Controller
{
    /**
     * Validate Binance API credentials and create a connection.
     */
    public function store(ConnectBinanceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

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
            'provider' => 'binance',
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
            'external_account_id' => 'binance-portfolio',
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        return response()->json([
            'redirect_url' => route('settings.connections.index'),
            'connection_id' => $connection->id,
        ]);
    }
}

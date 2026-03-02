<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\ConnectIndexaCapitalRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class IndexaCapitalController extends Controller
{
    /**
     * Validate the Indexa Capital API token and create a connection.
     */
    public function store(ConnectIndexaCapitalRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        $client = new IndexaCapitalClient($validated['api_token']);

        try {
            $userData = $client->getUser();
        } catch (\Throwable $e) {
            Log::warning('Indexa Capital token validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid API token or failed to connect to Indexa Capital.',
            ], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Indexa Capital', 'user_id' => null],
            ['name' => 'Indexa Capital', 'logo' => '/images/banks/logos/indexa-capital.jpg'],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => 'indexacapital',
            'api_token' => $validated['api_token'],
            'aspsp_name' => 'Indexa Capital',
            'aspsp_country' => 'ES',
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $pendingAccounts = $this->buildPendingAccounts($userData);

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

        foreach ($pendingAccounts as $accountData) {
            $user->accounts()->create([
                'name' => $accountData['name'],
                'name_iv' => null,
                'encrypted' => false,
                'bank_id' => $bank->id,
                'currency_code' => $accountData['currency'],
                'type' => AccountType::Investment->value,
                'banking_connection_id' => $connection->id,
                'external_account_id' => $accountData['uid'],
            ]);
        }

        SyncBankingConnectionJob::dispatch($connection);

        $successRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $redirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        return response()->json([
            'redirect_url' => route($successRedirect, $redirectParams),
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Build the pending accounts data in the same format as EnableBanking.
     *
     * @return array<int, array{uid: string, currency: string, name: string}>
     */
    private function buildPendingAccounts(array $userData): array
    {
        $accounts = [];

        foreach ($userData['accounts'] ?? [] as $account) {
            $accountNumber = $account['account_number'] ?? null;

            if (! $accountNumber) {
                continue;
            }

            $type = $account['type'] ?? 'mutual';
            $typeName = $type === 'pension' ? 'Pension Plan' : 'Investment Portfolio';

            $accounts[] = [
                'uid' => $accountNumber,
                'currency' => 'EUR',
                'name' => "{$typeName} ({$accountNumber})",
            ];
        }

        return $accounts;
    }
}

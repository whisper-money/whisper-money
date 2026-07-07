<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\PlaidClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PlaidController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Create a Plaid Link token for the frontend SDK.
     */
    public function createLinkToken(Request $request): JsonResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        try {
            $client = new PlaidClient;
            $result = $client->createLinkToken((string) $user->id);

            return response()->json([
                'link_token' => $result['link_token'],
                'expiration' => $result['expiration'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to create Plaid link token', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to create Plaid Link token. Please check your Plaid configuration.',
            ], 500);
        }
    }

    /**
     * Exchange a Plaid public_token for an access_token and create a connection.
     */
    public function store(Request $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $request->validate([
            'public_token' => ['required', 'string', 'min:10'],
        ]);

        try {
            $client = new PlaidClient;
            $exchange = $client->exchangePublicToken($request->public_token);

            $accessToken = $exchange['access_token'];
            $itemId = $exchange['item_id'];

            // Fetch accounts from Plaid to build pending accounts
            $clientWithAccess = new PlaidClient($accessToken, $itemId);
            $plaidAccounts = $clientWithAccess->getAccounts();

            $pendingAccounts = $this->buildPendingAccounts($plaidAccounts['accounts'] ?? []);

            if ($pendingAccounts === []) {
                return response()->json(['message' => 'No accounts found for this Plaid Link token.'], 422);
            }

            $bank = Bank::firstOrCreate(
                ['name' => 'Plaid', 'user_id' => null],
                ['name' => 'Plaid', 'logo' => null],
            );

            $connection = $user->bankingConnections()->create([
                'provider' => BankingProvider::Plaid,
                'api_token' => $accessToken,
                'api_secret' => $itemId,
                'aspsp_name' => 'Plaid',
                'aspsp_country' => 'US',
                'aspsp_logo' => $bank->logo,
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
        } catch (\Throwable $e) {
            Log::error('Failed to connect Plaid', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Failed to connect Plaid. Please try again.',
            ], 422);
        }
    }

    /**
     * @param  array<int, array{account_id?: string, name?: string, official_name?: string, type?: string, subtype?: string, balances?: array{iso_currency_code?: string}}>  $plaidAccounts
     * @return array<int, array{uid: string, currency: string, name: string}>
     */
    private function buildPendingAccounts(array $plaidAccounts): array
    {
        $pending = [];

        foreach ($plaidAccounts as $account) {
            $accountId = $account['account_id'] ?? null;

            if ($accountId === null) {
                continue;
            }

            $currency = $account['balances']['iso_currency_code'] ?? 'USD';
            $name = $account['official_name'] ?? $account['name'] ?? 'Plaid Account';

            $pending[] = [
                'uid' => $accountId,
                'currency' => $currency,
                'name' => $name,
            ];
        }

        return $pending;
    }
}

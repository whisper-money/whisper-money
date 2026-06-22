<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectWiseRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\WiseClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class WiseController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate a Wise API token and create a connection.
     *
     * Every currency wallet across all of the token's profiles (personal and
     * business) becomes a pending account with
     * external_account_id = "{profileId}:{currency}".
     */
    public function store(ConnectWiseRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $client = new WiseClient($request->api_token);

        try {
            $profiles = $client->getProfiles();
        } catch (\Throwable $e) {
            Log::warning('Wise credential validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid API token or failed to connect to Wise.',
            ], 422);
        }

        $pendingAccounts = $this->buildPendingAccounts($client, $profiles);

        if ($pendingAccounts === []) {
            return response()->json(['message' => 'No Wise multi-currency account found for this token.'], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Wise', 'user_id' => null],
            ['name' => 'Wise', 'logo' => null],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => BankingProvider::Wise,
            ...BankingProvider::Wise->credentialColumns($request->validated()),
            'aspsp_name' => 'Wise',
            'aspsp_country' => 'GB',
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
    }

    /**
     * Build pending account entries across every profile on the token.
     *
     * Each currency wallet becomes one pending account with
     * external_account_id = "{profileId}:{currency}", the format the Wise
     * sync services use to fetch activities and balances per profile.
     *
     * @param  array<int, array{id?: int, type?: string, details?: array<string, mixed>}>  $profiles
     * @return array<int, array{uid: string, currency: string, name: string}>
     */
    private function buildPendingAccounts(WiseClient $client, array $profiles): array
    {
        $pendingAccounts = [];

        foreach ($profiles as $profile) {
            $profileId = $profile['id'] ?? null;

            if ($profileId === null) {
                continue;
            }

            try {
                $borderlessAccount = $client->getBorderlessAccount($profileId);
            } catch (\Throwable $e) {
                Log::warning('Failed to load Wise borderless account', [
                    'profile_id' => $profileId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            $label = ucfirst((string) ($profile['type'] ?? 'account'));

            foreach ($borderlessAccount['balances'] ?? [] as $balance) {
                $currency = $balance['currency'] ?? null;

                if ($currency === null) {
                    continue;
                }

                $pendingAccounts[] = [
                    'uid' => $profileId.':'.$currency,
                    'currency' => $currency,
                    'name' => 'Wise '.$label.' '.$currency,
                ];
            }
        }

        return $pendingAccounts;
    }
}

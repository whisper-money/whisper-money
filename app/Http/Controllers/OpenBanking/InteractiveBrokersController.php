<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Features\InteractiveBrokers;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectInteractiveBrokersRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\InteractiveBrokersClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class InteractiveBrokersController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate the Flex credentials and create a connection.
     */
    public function store(ConnectInteractiveBrokersRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        abort_unless(Feature::for($user)->active(InteractiveBrokers::class), 403);

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $client = new InteractiveBrokersClient($validated['token'], $validated['query_id']);

        try {
            $accounts = $client->fetchStatement();
        } catch (\Throwable $e) {
            Log::warning('Interactive Brokers connection validation failed', ['error' => $e->getMessage()]);

            return response()->json([
                'message' => 'Invalid Flex token or query ID, or failed to connect to Interactive Brokers.',
            ], 422);
        }

        if (empty($accounts)) {
            return response()->json([
                'message' => 'No accounts found in the Flex statement. Check that your Flex Query includes the NAV section.',
            ], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => 'Interactive Brokers', 'user_id' => null],
            ['name' => 'Interactive Brokers', 'logo' => '/images/banks/logos/interactive-brokers.svg'],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => BankingProvider::InteractiveBrokers,
            'api_token' => $validated['token'],
            'api_secret' => $validated['query_id'],
            'aspsp_name' => 'Interactive Brokers',
            'aspsp_country' => 'US',
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $connection->update([
            'status' => BankingConnectionStatus::AwaitingMapping,
            'pending_accounts_data' => $this->buildPendingAccounts($accounts),
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
     * Build pending accounts from the parsed Flex statement.
     *
     * @param  array<string, array{account_id: string, currency: string, navByDate: array<string, float>, investedAmount: float|null}>  $accounts
     * @return array<int, array{uid: string, currency: string, name: string}>
     */
    private function buildPendingAccounts(array $accounts): array
    {
        $pending = [];

        foreach ($accounts as $account) {
            $pending[] = [
                'uid' => $account['account_id'],
                'currency' => $account['currency'] !== '' ? $account['currency'] : 'USD',
                'name' => "Interactive Brokers ({$account['account_id']})",
            ];
        }

        return $pending;
    }
}

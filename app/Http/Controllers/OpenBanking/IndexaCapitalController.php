<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\ConnectIndexaCapitalRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Services\AccountUserCurrencyService;
use App\Services\Banking\IndexaCapitalClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class IndexaCapitalController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Validate the Indexa Capital API token and create a connection.
     */
    public function store(ConnectIndexaCapitalRequest $request, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $validated = $request->validated();
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

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
            'provider' => BankingProvider::IndexaCapital,
            'api_token' => $validated['api_token'],
            'aspsp_name' => 'Indexa Capital',
            'aspsp_country' => 'ES',
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $pendingAccounts = $this->buildPendingAccounts($userData);

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

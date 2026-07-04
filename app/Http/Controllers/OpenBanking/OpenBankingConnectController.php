<?php

namespace App\Http\Controllers\OpenBanking;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

abstract class OpenBankingConnectController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    abstract protected function provider(): BankingProvider;

    /**
     * Display name used for both the Bank record and the connection's aspsp_name.
     */
    abstract protected function providerName(): string;

    abstract protected function bankLogo(): ?string;

    /**
     * @param  array<string, mixed>  $validated
     */
    abstract protected function aspspCountry(array $validated): string;

    /**
     * Build the provider client, validate the credentials, and return whatever
     * data is needed to build pending accounts (or null). Must throw on failure.
     *
     * @param  array<string, mixed>  $validated
     */
    abstract protected function fetchProviderData(array $validated): mixed;

    abstract protected function credentialErrorMessage(\Throwable $e): string;

    /**
     * @return array<int, array{uid: string, currency: string, name: string}>
     */
    abstract protected function buildPendingAccounts(mixed $providerData, User $user): array;

    /**
     * Optional guard: return a 422 message when the validated provider data is
     * unusable (e.g. an empty statement). Returning null keeps the flow going.
     */
    protected function emptyProviderDataMessage(mixed $providerData): ?string
    {
        return null;
    }

    /**
     * Shared connect flow: gate on subscription, validate the credentials, create
     * the pending connection, then auto-map (onboarding) or redirect to mapping.
     *
     * @param  array<string, mixed>  $validated
     */
    protected function connect(array $validated, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        try {
            $providerData = $this->fetchProviderData($validated);
        } catch (\Throwable $e) {
            Log::warning('OpenBanking credential validation failed', [
                'provider' => $this->provider()->value,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $this->credentialErrorMessage($e),
            ], 422);
        }

        if (($message = $this->emptyProviderDataMessage($providerData)) !== null) {
            return response()->json(['message' => $message], 422);
        }

        $bank = Bank::firstOrCreate(
            ['name' => $this->providerName(), 'user_id' => null],
            ['name' => $this->providerName(), 'logo' => $this->bankLogo()],
        );

        $connection = $user->bankingConnections()->create([
            'provider' => $this->provider(),
            ...$this->provider()->credentialColumns($validated),
            'aspsp_name' => $this->providerName(),
            'aspsp_country' => $this->aspspCountry($validated),
            'aspsp_logo' => $bank->logo,
            'status' => BankingConnectionStatus::Pending,
        ]);

        $connection->update([
            'status' => BankingConnectionStatus::AwaitingMapping,
            'pending_accounts_data' => $this->buildPendingAccounts($providerData, $user),
        ]);

        return $this->connectionResponse($user, $connection, $accountUserCurrencyService);
    }

    private function connectionResponse(User $user, BankingConnection $connection, AccountUserCurrencyService $accountUserCurrencyService): JsonResponse
    {
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

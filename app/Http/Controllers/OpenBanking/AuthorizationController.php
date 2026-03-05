<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\AccountType;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\StartAuthorizationRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\Bank;
use App\Models\BankingConnection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Pennant\Feature;

class AuthorizationController extends Controller
{
    /**
     * Start the bank authorization flow.
     */
    public function store(StartAuthorizationRequest $request, BankingProviderInterface $provider): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        if (config('subscriptions.enabled') && ! $user->hasProPlan()) {
            return response()->json(['redirect' => route('subscribe')], 402);
        }

        $validated = $request->validated();

        $redirectUrl = config('services.enablebanking.redirect_url');

        $result = $provider->startAuthorization(
            $validated['aspsp_name'],
            $validated['country'],
            $redirectUrl,
        );

        $connection = $user->bankingConnections()->create([
            'provider' => 'enablebanking',
            'authorization_id' => $result['authorization_id'],
            'aspsp_name' => $validated['aspsp_name'],
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $validated['logo'] ?? null,
            'status' => BankingConnectionStatus::Pending,
        ]);

        return response()->json([
            'redirect_url' => $result['url'],
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Handle the callback from bank authorization.
     */
    public function callback(Request $request, BankingProviderInterface $provider): RedirectResponse
    {
        $user = auth()->user();
        $errorRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';

        if ($request->has('error')) {
            Log::warning('EnableBanking authorization error', [
                'error' => $request->query('error'),
                'description' => $request->query('error_description'),
            ]);

            $user->bankingConnections()
                ->where('status', BankingConnectionStatus::Pending)
                ->latest()
                ->first()
                ?->delete();

            return redirect()->route($errorRedirect)
                ->with('error', $request->query('error_description', 'Authorization was denied or cancelled.'));
        }

        $code = $request->query('code');

        if (! $code) {
            return redirect()->route($errorRedirect)
                ->with('error', 'No authorization code received.');
        }

        try {
            $sessionData = $provider->createSession($code);
        } catch (\Throwable $e) {
            Log::error('EnableBanking session creation failed', ['error' => $e->getMessage()]);

            return redirect()->route($errorRedirect)
                ->with('error', 'Failed to connect to your bank. Please try again.');
        }

        $connection = $user->bankingConnections()
            ->where('status', BankingConnectionStatus::Pending)
            ->latest()
            ->first();

        if (! $connection) {
            return redirect()->route($errorRedirect)
                ->with('error', 'No pending connection found.');
        }

        if (Feature::for($user)->active('account-mapping')) {
            $connection->update([
                'session_id' => $sessionData['session_id'],
                'status' => BankingConnectionStatus::AwaitingMapping,
                'valid_until' => $sessionData['access']['valid_until'] ?? null,
                'pending_accounts_data' => $sessionData['accounts'],
            ]);

            if (! $user->isOnboarded()) {
                $this->createAccountsFromPending($user, $connection);
                SyncBankingConnectionJob::dispatch($connection);

                return redirect()->route('onboarding', ['step' => 'create-account'])
                    ->with('success', 'Bank account connected successfully.');
            }

            return redirect()->route('open-banking.map-accounts', $connection);
        }

        $connection->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::Active,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
        ]);

        $this->createAccountsFromSession($user, $connection, $sessionData);

        SyncBankingConnectionJob::dispatch($connection);

        $successRedirect = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $redirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        return redirect()->route($successRedirect, $redirectParams)
            ->with('success', 'Bank account connected successfully.');
    }

    /**
     * Auto-create accounts from pending_accounts_data without user interaction.
     */
    private function createAccountsFromPending($user, BankingConnection $connection): void
    {
        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        if (! $bank->logo && $connection->aspsp_logo) {
            $bank->update(['logo' => $connection->aspsp_logo]);
        }

        foreach ($connection->pending_accounts_data ?? [] as $accountData) {
            $uid = $accountData['uid'] ?? null;

            if (! $uid) {
                continue;
            }

            $currency = $accountData['currency'] ?? 'EUR';
            $name = $accountData['name']
                ?? $accountData['account_id']['iban']
                ?? $connection->aspsp_name.' Account';

            $user->accounts()->create([
                'name' => $name,
                'name_iv' => null,
                'encrypted' => false,
                'bank_id' => $bank->id,
                'currency_code' => $currency,
                'type' => AccountType::Checking->value,
                'banking_connection_id' => $connection->id,
                'external_account_id' => $uid,
            ]);
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'pending_accounts_data' => null,
        ]);
    }

    /**
     * Create local accounts from the EnableBanking session data.
     */
    private function createAccountsFromSession($user, BankingConnection $connection, array $sessionData): void
    {
        $bank = Bank::firstOrCreate(
            ['name' => $connection->aspsp_name, 'user_id' => null],
            ['name' => $connection->aspsp_name, 'logo' => $connection->aspsp_logo],
        );

        if (! $bank->logo && $connection->aspsp_logo) {
            $bank->update(['logo' => $connection->aspsp_logo]);
        }

        $accounts = $sessionData['accounts'] ?? [];

        foreach ($accounts as $accountData) {
            $uid = $accountData['uid'] ?? null;

            if (! $uid) {
                continue;
            }

            $existingAccount = $user->accounts()
                ->where('banking_connection_id', $connection->id)
                ->where('external_account_id', $uid)
                ->first();

            if ($existingAccount) {
                continue;
            }

            $currency = $accountData['currency'] ?? 'EUR';
            $name = $accountData['name']
                ?? $accountData['account_id']['iban']
                ?? $connection->aspsp_name.' Account';

            $user->accounts()->create([
                'name' => $name,
                'name_iv' => null,
                'encrypted' => false,
                'bank_id' => $bank->id,
                'currency_code' => $currency,
                'type' => AccountType::Checking->value,
                'banking_connection_id' => $connection->id,
                'external_account_id' => $uid,
            ]);
        }
    }
}

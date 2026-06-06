<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\StartAuthorizationRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class AuthorizationController extends Controller
{
    use CreatesAccountsFromPending;
    use HandlesSubscriptionGate;

    /**
     * Start the bank authorization flow.
     */
    public function store(StartAuthorizationRequest $request, BankingProviderInterface $provider): JsonResponse|RedirectResponse
    {
        $user = auth()->user();

        if ($this->shouldBlockOpenBankingAccess($user)) {
            return $this->subscribeJsonResponse();
        }

        $validated = $request->validated();

        $redirectUrl = config('services.enablebanking.redirect_url');
        $stateToken = Str::random(40);

        $result = $provider->startAuthorization(
            $validated['aspsp_name'],
            $validated['country'],
            $redirectUrl,
            $stateToken,
        );

        $connection = $user->bankingConnections()->create([
            'provider' => 'enablebanking',
            'authorization_id' => $result['authorization_id'],
            'state_token' => $stateToken,
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
     * Re-authorize an existing EnableBanking connection whose session has been revoked.
     */
    public function reauthorize(Request $request, BankingConnection $connection, BankingProviderInterface $provider): JsonResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if ($this->shouldBlockOpenBankingAccess($request->user())) {
            return $this->subscribeJsonResponse();
        }

        $result = $this->startReauthorization($connection, $provider);

        if (isset($result['error'])) {
            return response()->json(['error' => $result['error']], 422);
        }

        return response()->json([
            'redirect_url' => $result['url'],
            'connection_id' => $connection->id,
        ]);
    }

    /**
     * Start reconnect flow from email or UI links.
     */
    public function reconnect(Request $request, BankingConnection $connection, BankingProviderInterface $provider): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if ($this->shouldBlockOpenBankingAccess($request->user())) {
            return $this->subscribeRedirectResponse();
        }

        $result = $this->startReauthorization($connection, $provider);

        if (isset($result['error'])) {
            return redirect()->route('settings.connections.index')
                ->with('error', $result['error']);
        }

        return redirect()->away($result['url']);
    }

    /**
     * @return array{url?: string, authorization_id?: string, error?: string}
     */
    private function startReauthorization(BankingConnection $connection, BankingProviderInterface $provider): array
    {
        if (! $connection->isEnableBanking()) {
            return ['error' => 'Only EnableBanking connections can be re-authorized.'];
        }

        if ($connection->status !== BankingConnectionStatus::Error && ! $connection->isExpired()) {
            return ['error' => 'Only connections with an error or expired status can be re-authorized.'];
        }

        $redirectUrl = config('services.enablebanking.redirect_url');
        $stateToken = Str::random(40);

        $result = $provider->startAuthorization(
            $connection->aspsp_name,
            $connection->aspsp_country,
            $redirectUrl,
            $stateToken,
        );

        $connection->update([
            'authorization_id' => $result['authorization_id'],
            'state_token' => $stateToken,
            'status' => BankingConnectionStatus::Pending,
            'error_message' => null,
        ]);

        return $result;
    }

    /**
     * Handle the callback from bank authorization.
     *
     * This route is intentionally unauthenticated. iOS PWAs hand the bank redirect
     * back to the system browser (Safari), where the app session does not exist, so
     * the connection is resolved from the signed state token EnableBanking echoes
     * back rather than from the logged-in session.
     */
    public function callback(Request $request, BankingProviderInterface $provider, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse|Response
    {
        $connection = $this->resolveConnectionFromState($request);
        $user = $connection ? $connection->user : auth()->user();

        if (! $user) {
            return redirect()->route('login')
                ->with('error', __('Please log back in to finish connecting your bank account.'));
        }

        $errorRedirectRoute = $user->isOnboarded() ? 'settings.connections.index' : 'onboarding';
        $errorRedirectParams = $user->isOnboarded() ? [] : ['step' => 'create-account'];

        if ($request->has('error')) {
            $errorDescription = $request->query('error_description');
            $errorMessage = is_string($errorDescription) && $errorDescription !== ''
                ? $errorDescription
                : 'Authorization was denied or cancelled.';

            Log::warning('EnableBanking authorization error', [
                'error' => $request->query('error'),
                'description' => $errorDescription,
            ]);

            $pendingConnection = $connection ?? $user->bankingConnections()
                ->where('status', BankingConnectionStatus::Pending)
                ->latest()
                ->first();

            if ($pendingConnection) {
                if ($pendingConnection->accounts()->exists()) {
                    $pendingConnection->update([
                        'status' => BankingConnectionStatus::Error,
                        'error_message' => $errorMessage,
                        'state_token' => null,
                    ]);
                } else {
                    $pendingConnection->delete();
                }
            }

            return $this->finishRedirect($errorRedirectRoute, $errorRedirectParams, 'error', $errorMessage);
        }

        $code = $request->query('code');

        if (! $code) {
            return $this->finishRedirect($errorRedirectRoute, $errorRedirectParams, 'error', 'No authorization code received.');
        }

        try {
            $sessionData = $provider->createSession($code);
        } catch (\Throwable $e) {
            Log::error('EnableBanking session creation failed', ['error' => $e->getMessage()]);

            return $this->finishRedirect($errorRedirectRoute, $errorRedirectParams, 'error', 'Failed to connect to your bank. Please try again.');
        }

        $connection ??= $this->findPendingConnectionForSession($user, $sessionData);

        if (! $connection) {
            return $this->finishRedirect($errorRedirectRoute, $errorRedirectParams, 'error', 'No pending connection found.');
        }

        $isReconnect = $connection->accounts()->exists();

        if ($isReconnect) {
            $connection->update([
                'session_id' => $sessionData['session_id'],
                'status' => BankingConnectionStatus::Active,
                'valid_until' => $sessionData['access']['valid_until'] ?? null,
                'error_message' => null,
                'state_token' => null,
            ]);

            $this->refreshAccountIds($connection, $sessionData['accounts']);

            SyncBankingConnectionJob::dispatch($connection);

            return $this->finishRedirect('settings.connections.index', [], 'success', __('Bank account reconnected successfully.'));
        }

        $connection->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::AwaitingMapping,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'pending_accounts_data' => $sessionData['accounts'],
            'state_token' => null,
        ]);

        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection);

            return $this->finishRedirect('onboarding', ['step' => 'create-account'], 'success', 'Bank account connected successfully.');
        }

        return $this->finishRedirect('open-banking.map-accounts', ['connection' => $connection]);
    }

    /**
     * Resolve the connection a callback belongs to from the state token EnableBanking
     * echoes back. This works without a logged-in session.
     */
    private function resolveConnectionFromState(Request $request): ?BankingConnection
    {
        $stateToken = $request->query('state');

        if (! is_string($stateToken) || $stateToken === '') {
            return null;
        }

        return BankingConnection::query()
            ->where('state_token', $stateToken)
            ->first();
    }

    /**
     * Finish a callback, accounting for the unauthenticated PWA case.
     *
     * When the callback runs without a session (Safari), the destination routes are
     * behind auth middleware and the user is not logged in on this browser. The
     * connection has already been finalized server-side, so render a standalone page
     * telling the user to return to the app rather than bouncing them to login.
     *
     * @param  array<string, mixed>  $params
     */
    private function finishRedirect(string $route, array $params, ?string $flashKey = null, ?string $flashMessage = null): RedirectResponse|Response
    {
        if (! Auth::check()) {
            return Inertia::render('open-banking/connection-complete', [
                'status' => $flashKey === 'error' ? 'error' : 'success',
                'message' => $flashMessage ?? __('Your bank account is connected.'),
            ]);
        }

        $redirect = redirect()->route($route, $params);

        if ($flashKey !== null && $flashMessage !== null) {
            $redirect->with($flashKey, $flashMessage);
        }

        return $redirect;
    }

    /**
     * Find the pending connection that belongs to the callback session.
     *
     * Multiple reconnection flows may be pending at the same time. Never pick an
     * arbitrary latest connection, because that can attach one bank's session and
     * transactions to another bank's existing account.
     *
     * @param  array{aspsp?: array{name?: string, country?: string}, accounts?: array<int, array<string, mixed>>}  $sessionData
     */
    private function findPendingConnectionForSession(User $user, array $sessionData): ?BankingConnection
    {
        $pendingConnections = $user->bankingConnections()
            ->where('status', BankingConnectionStatus::Pending)
            ->get();

        if ($pendingConnections->isEmpty()) {
            return null;
        }

        $aspspName = $sessionData['aspsp']['name'] ?? null;
        $aspspCountry = $sessionData['aspsp']['country'] ?? null;

        if (is_string($aspspName) && is_string($aspspCountry)) {
            $matchedByInstitution = $pendingConnections
                ->first(fn (BankingConnection $connection): bool => $connection->aspsp_name === $aspspName
                    && $connection->aspsp_country === $aspspCountry);

            if ($matchedByInstitution) {
                return $matchedByInstitution;
            }
        }

        $ibans = collect($sessionData['accounts'] ?? [])
            ->map(fn (array $account): ?string => $account['account_id']['iban'] ?? null)
            ->filter()
            ->values();

        if ($ibans->isNotEmpty()) {
            $matchedByIban = $pendingConnections
                ->first(fn (BankingConnection $connection): bool => $connection->accounts()
                    ->whereIn('iban', $ibans)
                    ->exists());

            if ($matchedByIban) {
                return $matchedByIban;
            }
        }

        if ($pendingConnections->count() === 1) {
            return $pendingConnections->first();
        }

        Log::warning('Unable to disambiguate pending EnableBanking callback', [
            'user_id' => $user->id,
            'pending_connection_ids' => $pendingConnections->pluck('id')->all(),
            'aspsp_name' => is_string($aspspName) ? $aspspName : null,
            'aspsp_country' => is_string($aspspCountry) ? $aspspCountry : null,
        ]);

        return null;
    }

    /**
     * Refresh external_account_id and iban on existing accounts after a reconnect.
     *
     * Enable Banking issues new account UIDs with every new session, so the stored
     * external_account_id values become invalid as soon as the old session expires.
     *
     * Matching strategy (in priority order):
     *   1. Match by IBAN — reliable when the account was created after the iban column existed.
     *   2. Positional fallback — match by creation order for legacy accounts without a stored IBAN.
     *
     * @param  array<int, array<string, mixed>>  $newAccounts
     */
    private function refreshAccountIds(BankingConnection $connection, array $newAccounts): void
    {
        if (empty($newAccounts)) {
            return;
        }

        $existingAccounts = $connection->accounts()->orderBy('created_at')->get();

        $unmatchedNew = collect($newAccounts);
        $unmatchedExisting = collect();

        foreach ($existingAccounts as $account) {
            if ($account->iban) {
                $matched = $unmatchedNew->first(fn (array $data) => ($data['account_id']['iban'] ?? null) === $account->iban);

                if ($matched) {
                    $account->update([
                        'external_account_id' => $matched['uid'],
                        'iban' => $matched['account_id']['iban'] ?? $account->iban,
                    ]);
                    $unmatchedNew = $unmatchedNew->reject(fn (array $data) => ($data['uid'] ?? null) === $matched['uid'])->values();

                    continue;
                }
            }

            $unmatchedExisting->push($account);
        }

        foreach ($unmatchedExisting as $index => $account) {
            $newAccountData = $unmatchedNew->get($index);

            if (! $newAccountData) {
                continue;
            }

            $account->update([
                'external_account_id' => $newAccountData['uid'],
                'iban' => $newAccountData['account_id']['iban'] ?? null,
            ]);
        }
    }
}

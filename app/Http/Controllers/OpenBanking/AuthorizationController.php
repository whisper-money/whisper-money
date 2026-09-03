<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Enums\BankingSyncTrigger;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\CreatesAccountsFromPending;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\StartAuthorizationRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\AccountUserCurrencyService;
use Illuminate\Database\Eloquent\SoftDeletingScope;
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
            'provider' => BankingProvider::EnableBanking,
            'authorization_id' => $result['authorization_id'],
            'state_token' => $stateToken,
            'aspsp_name' => $validated['aspsp_name'],
            'aspsp_country' => $validated['country'],
            'aspsp_logo' => $validated['logo'] ?? null,
            // Denormalised from the bank picker, exactly like the logo above it:
            // the connections screen renders stored connections and never sees
            // the provider's catalogue again. `banking:backfill-aspsp-beta`
            // repairs the rows the picker never told us about.
            //
            // A picker that says nothing means "not beta", never "unknown": it
            // has just read the catalogue, so silence is an answer. The backfill
            // command is the one that leaves a row null, because a bank missing
            // from the catalogue really is unknown.
            'aspsp_beta' => $request->boolean('beta'),
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

        // Active is in here so the user can renew a healthy consent before it
        // lapses: the alternative is a few days of broken syncing at the end of
        // every window. Which day the offer appears is the UI's and the warning
        // email's business, not this gate's.
        $renewable = $connection->isExpired() || in_array($connection->status, [
            BankingConnectionStatus::Active,
            BankingConnectionStatus::Error,
        ], true);

        if (! $renewable) {
            return ['error' => 'Only active, error or expired connections can be re-authorized.'];
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
     *
     * That split is also a race: the return URL is back inside the PWA scope, so
     * iOS may deliver it to the app, to Safari, or to both. The context that did
     * not run the SCA reports `access_denied` seconds after the flow starts and
     * soft-deletes the connection the other one is still authorizing. So the
     * callback carrying a code the provider accepts wins whatever the arrival
     * order: a code EnableBanking honours is proof the user did authorize, and
     * nothing weaker resurrects a row. Completion burns the state token, which
     * is what keeps a finished connection out of a second callback's reach.
     */
    public function callback(Request $request, BankingProviderInterface $provider, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse|Response
    {
        $connection = $this->resolveConnectionFromState($request);
        $user = $connection ? $connection->user : auth()->user();

        if (! $user) {
            // Silent until now, which made "the bank never came back" and "it
            // came back and we dropped it" indistinguishable in the logs.
            // Presence only: the state token and the code are credentials.
            Log::warning('EnableBanking callback could not be attributed to a user', [
                'has_state' => $request->has('state'),
                'has_code' => $request->has('code'),
            ]);

            return redirect()->route('login')
                ->with('error', __('Please log back in to finish connecting your bank account.'));
        }

        if ($request->has('error')) {
            return $this->handleAuthorizationError($request, $user, $connection);
        }

        $code = $request->query('code');

        // query() hands back an array for ?code[]=, which is truthy but not a code.
        if (! $code || ! is_string($code)) {
            return $this->finishWithError($user, 'No authorization code received.');
        }

        $sessionData = $this->createProviderSession($provider, $code, $connection);

        if ($sessionData === null) {
            return $this->finishWithError($user, 'Failed to connect to your bank. Please try again.');
        }

        $connection ??= $this->findPendingConnectionForSession($user, $sessionData);

        if (! $connection) {
            return $this->finishWithError($user, 'No pending connection found.');
        }

        // Undo the cancellation an orphaned context sent while the user was
        // still at their bank. See the race in this method's docblock.
        if ($connection->trashed()) {
            $connection->restore();
        }

        $isReconnect = $connection->accounts()->exists();

        if ($isReconnect) {
            return $this->completeReconnect($connection, $sessionData);
        }

        return $this->completeFirstConnection($user, $connection, $sessionData, $accountUserCurrencyService);
    }

    /**
     * Exchange the authorization code for a provider session, or null when the
     * exchange fails. A failed exchange burns the state token with it.
     *
     * @return array<string, mixed>|null
     */
    private function createProviderSession(BankingProviderInterface $provider, string $code, ?BankingConnection $connection): ?array
    {
        try {
            return $provider->createSession($code);
        } catch (\Throwable $e) {
            Log::error('EnableBanking session creation failed', ['error' => $e->getMessage()]);

            if ($connection) {
                $connection->update(['state_token' => null]);
            }

            return null;
        }
    }

    /**
     * Point a connection that already has accounts at its new session and resync it.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function completeReconnect(BankingConnection $connection, array $sessionData): RedirectResponse|Response
    {
        $connection->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::Active,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'error_message' => null,
            'state_token' => null,
            // Reconnecting is the way out of a parked connection, so it has
            // to hand back the full retry budget. Carrying the old count over
            // meant the first failure after a reconnect could re-park it
            // immediately, which is the state the user just paid SCA to leave.
            'consecutive_sync_failures' => 0,
        ]);

        $this->refreshAccountIds($connection, $sessionData['accounts']);

        SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::Reconnect);

        return $this->finishRedirect('settings.connections.index', [], 'success', __('Bank account reconnected successfully.'));
    }

    /**
     * Park the fetched accounts on the connection so the user can map them.
     *
     * Onboarding skips the mapping screen: every account is created up front so the
     * user lands back on the onboarding step with data already syncing.
     *
     * @param  array<string, mixed>  $sessionData
     */
    private function completeFirstConnection(User $user, BankingConnection $connection, array $sessionData, AccountUserCurrencyService $accountUserCurrencyService): RedirectResponse|Response
    {
        $connection->update([
            'session_id' => $sessionData['session_id'],
            'status' => BankingConnectionStatus::AwaitingMapping,
            'valid_until' => $sessionData['access']['valid_until'] ?? null,
            'pending_accounts_data' => $sessionData['accounts'],
            'state_token' => null,
        ]);

        if (! $user->isOnboarded()) {
            $this->createAccountsFromPending($user, $connection, $accountUserCurrencyService);
            SyncBankingConnectionJob::dispatch($connection, trigger: BankingSyncTrigger::Connect);

            return $this->finishRedirect('onboarding', ['step' => 'create-account'], 'success', 'Bank account connected successfully.');
        }

        return $this->finishRedirect('open-banking.map-accounts', ['connection' => $connection]);
    }

    /**
     * Clean up after an authorization the bank did not complete.
     *
     * A pending connection that already has accounts is a reconnect, so it is kept
     * and marked as failing. A brand new one has nothing worth keeping.
     *
     * Only the connection the state token names is touched. The old fallback -
     * the user's latest pending connection - deleted whatever it happened to
     * find: in production an error belonging to a 10:57 attempt deleted the
     * connection started at 09:41. An error we cannot attribute is still shown
     * to the user, but it takes no row down with it.
     *
     * The state token deliberately survives an error, so the winner of the race
     * in callback() can still claim this attempt.
     */
    private function handleAuthorizationError(Request $request, User $user, ?BankingConnection $connection): RedirectResponse|Response
    {
        $errorCode = $request->query('error');
        $errorDescription = $request->query('error_description');
        $errorMessage = $this->authorizationErrorMessage(
            is_string($errorCode) ? $errorCode : null,
            is_string($errorDescription) ? $errorDescription : null,
        );

        // The bank is logged because these failures are bank-specific, and the
        // connection row is about to be deleted on one of the branches below.
        Log::warning('EnableBanking authorization error', [
            'error' => $errorCode,
            'description' => $errorDescription,
            'aspsp_name' => $connection?->aspsp_name,
            'connection_id' => $connection?->id,
        ]);

        if ($connection) {
            if ($connection->accounts()->exists()) {
                $connection->update([
                    'status' => BankingConnectionStatus::Error,
                    'error_message' => $errorMessage,
                ]);
            } else {
                $connection->delete();
            }
        }

        return $this->finishWithError($user, $errorMessage);
    }

    /**
     * The message the user sees when an authorization comes back as an error.
     *
     * `access_denied` is the only code the user causes, and the only one whose
     * description is worth showing ("Cancelled by user"). The rest are ours or the
     * bank's to fix and arrive either with no description at all or with
     * untranslated prose addressed to us, so they get our own copy — worded
     * without pinning it on the bank, since `invalid_client` is our credentials.
     */
    private function authorizationErrorMessage(?string $errorCode, ?string $errorDescription): string
    {
        if ($errorCode !== 'access_denied') {
            return __('We could not complete the connection with your bank. Please try again later.');
        }

        return $errorDescription === null || $errorDescription === ''
            ? __('Authorization was denied or cancelled.')
            : $errorDescription;
    }

    /**
     * Abandon the callback with a message, sending the user wherever they came from.
     *
     * A user still onboarding has no connections screen to land on yet.
     */
    private function finishWithError(User $user, string $message): RedirectResponse|Response
    {
        return $user->isOnboarded()
            ? $this->finishRedirect('settings.connections.index', [], 'error', $message)
            : $this->finishRedirect('onboarding', ['step' => 'create-account'], 'error', $message);
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

        // Trashed rows count: a cancelled attempt is exactly what the winner of
        // the race in callback() has to land on. `state_token` is unique, so a
        // trashed row still holds the index and this stays single-valued.
        return BankingConnection::query()
            ->withoutGlobalScope(SoftDeletingScope::class)
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

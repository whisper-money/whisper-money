<?php

namespace App\Http\Controllers\OpenBanking;

use App\Actions\OpenBanking\DisconnectBankingConnection;
use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Http\Controllers\Controller;
use App\Http\Controllers\OpenBanking\Concerns\HandlesSubscriptionGate;
use App\Http\Requests\OpenBanking\DestroyConnectionRequest;
use App\Http\Requests\OpenBanking\UpdateConnectionCredentialsRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use App\Models\User;
use App\Services\Banking\BinanceClient;
use App\Services\Banking\BitpandaClient;
use App\Services\Banking\CoinbaseClient;
use App\Services\Banking\IndexaCapitalClient;
use App\Services\Banking\InteractiveBrokersClient;
use App\Services\Banking\WiseClient;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    use AuthorizesRequests;
    use HandlesSubscriptionGate;

    /**
     * Show the user's banking connections.
     */
    public function index(): Response
    {
        /** @var User $user */
        $user = Auth::user();

        $nextScheduledSync = $this->nextScheduledSyncAt();

        $connections = $user->bankingConnections()
            ->withCount('accounts')
            ->orderByDesc('created_at')
            ->get()
            ->each(function (BankingConnection $connection) use ($nextScheduledSync) {
                $connection->has_pending_accounts = $connection->hasPendingAccounts();
                $connection->can_sync_manually = ! $connection->isRateLimited();
                $connection->next_sync_attempt_at = $nextScheduledSync?->max($connection->rate_limited_until);
            });

        return Inertia::render('settings/connections', [
            'connections' => $connections,
        ]);
    }

    /**
     * When the scheduler will next reach this connection.
     *
     * A rate limit backoff is an hour by default while the sweep runs every six,
     * so `rate_limited_until` on its own promises an attempt that will not happen
     * and, once it lapses, promises nothing at all. Whichever comes later is the
     * only figure that is true.
     */
    private function nextScheduledSyncAt(): ?Carbon
    {
        // routes/console.php is only read when the console kernel boots, which a
        // web request never does on its own. Without this the schedule is empty
        // here and the user is told nothing about when we will try again.
        app(ConsoleKernel::class)->bootstrap();

        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains($event->command ?? '', 'banking:sync'));

        return $event ? Carbon::instance($event->nextRunDate()) : null;
    }

    /**
     * Manually trigger a sync for a connection.
     */
    public function sync(BankingConnection $connection): RedirectResponse
    {
        if ($connection->user_id !== Auth::id()) {
            abort(403);
        }

        if ($this->shouldBlockOpenBankingAccess(Auth::user(), false)) {
            return $this->subscribeRedirectResponse();
        }

        if (! $connection->isActive() && $connection->status !== BankingConnectionStatus::Error) {
            return back()->with('error', 'Connection is not active.');
        }

        // A live backoff is the bank having told us to stop. Spending an access
        // call anyway buys another refusal and burns the scheduled run, and the
        // card already tells the user when we will try again. Only the healthy
        // path is refused: a connection in Error is one the scheduler has given
        // up on, so a manual retry is its only way back.
        if ($connection->isActive() && $connection->isRateLimited()) {
            return back()->with('error', __('Your bank limits how often we can fetch your data. We will retry automatically.'));
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
            // Only a stranded connection reaches this with a backoff still set,
            // and there the retry is the way back. Leaving it would make the job
            // return early while this flashed "sync started" and nothing
            // happened, for as long as the window had left.
            'rate_limited_until' => null,
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        return back()->with('success', 'Sync started. Transactions will be updated shortly.');
    }

    /**
     * Update credentials for an API-key-based connection.
     */
    public function updateCredentials(UpdateConnectionCredentialsRequest $request, BankingConnection $connection): RedirectResponse
    {
        if ($this->shouldBlockOpenBankingAccess($request->user(), false)) {
            return $this->subscribeRedirectResponse();
        }

        $validated = $request->validated();

        $validationError = $this->validateProviderCredentials($connection, $validated);

        if ($validationError) {
            return back()->withErrors(['credentials' => $validationError]);
        }

        $connection->update([
            ...$connection->provider->credentialColumns($validated),
            'status' => BankingConnectionStatus::Active,
            'error_message' => null,
            'consecutive_sync_failures' => 0,
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        return back()->with('success', __('Credentials updated. Sync started.'));
    }

    /**
     * Validate credentials against the provider API.
     */
    private function validateProviderCredentials(BankingConnection $connection, array $validated): ?string
    {
        try {
            match ($connection->provider) {
                BankingProvider::IndexaCapital => (new IndexaCapitalClient($validated['api_token']))->getUser(),
                BankingProvider::Binance => (new BinanceClient($validated['api_key'], $validated['api_secret']))->getAccount(),
                BankingProvider::Bitpanda => (new BitpandaClient($validated['api_key']))->getCryptoWallets(),
                BankingProvider::Coinbase => (new CoinbaseClient($validated['api_key_name'], $validated['private_key']))->getAccounts(limit: 1),
                BankingProvider::InteractiveBrokers => (new InteractiveBrokersClient($validated['token'], $validated['query_id']))->fetchStatement(),
                BankingProvider::Wise => (new WiseClient($validated['api_token']))->getProfiles(),
                default => throw new \InvalidArgumentException('Unsupported provider for credential update.'),
            };
        } catch (\InvalidArgumentException $e) {
            return $e->getMessage();
        } catch (\Throwable $e) {
            Log::warning('Credential validation failed during update', [
                'connection_id' => $connection->id,
                'provider' => $connection->provider->value,
                'error' => $e->getMessage(),
            ]);

            return __('Invalid credentials. Please check and try again.');
        }

        return null;
    }

    /**
     * Revoke and delete a banking connection.
     */
    public function destroy(DestroyConnectionRequest $request, BankingConnection $connection, DisconnectBankingConnection $disconnectBankingConnection): RedirectResponse
    {
        $disconnectBankingConnection->handle($connection, $request->boolean('delete_accounts'));

        return redirect()->route('settings.connections.index')
            ->with('success', 'Banking connection disconnected.');
    }
}

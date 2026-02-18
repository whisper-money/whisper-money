<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\OpenBanking\DestroyConnectionRequest;
use App\Jobs\SyncBankingConnectionJob;
use App\Models\BankingConnection;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ConnectionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Show the user's banking connections.
     */
    public function index(): Response
    {
        $connections = auth()->user()
            ->bankingConnections()
            ->withCount('accounts')
            ->orderByDesc('created_at')
            ->get()
            ->each(function ($connection) {
                $connection->has_pending_accounts = $connection->hasPendingAccounts();
            });

        return Inertia::render('settings/connections', [
            'connections' => $connections,
        ]);
    }

    /**
     * Manually trigger a sync for a connection.
     */
    public function sync(BankingConnection $connection): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if (! $connection->isActive() && $connection->status !== BankingConnectionStatus::Error) {
            return back()->with('error', 'Connection is not active.');
        }

        $connection->update([
            'status' => BankingConnectionStatus::Active,
            'error_message' => null,
        ]);

        SyncBankingConnectionJob::dispatch($connection);

        return back()->with('success', 'Sync started. Transactions will be updated shortly.');
    }

    /**
     * Revoke and delete a banking connection.
     */
    public function destroy(DestroyConnectionRequest $request, BankingConnection $connection, BankingProviderInterface $provider): RedirectResponse
    {
        if ($connection->isEnableBanking() && $connection->session_id && $connection->isActive()) {
            try {
                $provider->revokeSession($connection->session_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke EnableBanking session', [
                    'session_id' => $connection->session_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($request->boolean('delete_accounts')) {
            $connection->accounts->each(function ($account) {
                $account->transactions()->delete();
                $account->balances()->delete();
                $account->delete();
            });
        } else {
            $connection->accounts()->update([
                'banking_connection_id' => null,
                'external_account_id' => null,
            ]);
        }

        $connection->update(['status' => BankingConnectionStatus::Revoked]);
        $connection->delete();

        return redirect()->route('settings.connections.index')
            ->with('success', 'Banking connection disconnected.');
    }
}

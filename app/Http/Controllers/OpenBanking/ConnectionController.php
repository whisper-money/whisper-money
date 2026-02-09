<?php

namespace App\Http\Controllers\OpenBanking;

use App\Contracts\BankingProviderInterface;
use App\Enums\BankingConnectionStatus;
use App\Http\Controllers\Controller;
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
            ->get();

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

        if (! $connection->isActive()) {
            return back()->with('error', 'Connection is not active.');
        }

        SyncBankingConnectionJob::dispatch($connection);

        return back()->with('success', 'Sync started. Transactions will be updated shortly.');
    }

    /**
     * Revoke and delete a banking connection.
     */
    public function destroy(BankingConnection $connection, BankingProviderInterface $provider): RedirectResponse
    {
        if ($connection->user_id !== auth()->id()) {
            abort(403);
        }

        if ($connection->session_id && $connection->isActive()) {
            try {
                $provider->revokeSession($connection->session_id);
            } catch (\Throwable $e) {
                Log::warning('Failed to revoke EnableBanking session', [
                    'session_id' => $connection->session_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $connection->update(['status' => BankingConnectionStatus::Revoked]);
        $connection->delete();

        return redirect()->route('settings.connections.index')
            ->with('success', 'Banking connection disconnected.');
    }
}

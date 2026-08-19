<?php

namespace App\Console\Commands\Concerns;

use App\Enums\BankingConnectionStatus;
use App\Enums\BankingProvider;
use App\Jobs\SyncBankingConnectionJob;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Str;

/**
 * What "this bank is broken" means, in one place.
 *
 * There is one definition per failure mode: a bank whose authorization never
 * completes for anybody, and a bank whose working connections stopped answering.
 * They live here because two kinds of command read them — the ones that email
 * the affected users, and the one that reports which banks are in that state —
 * and a report that measured something subtly different from what the notice
 * claims would be worse than no report.
 *
 * A bank is identified by `aspsp_name` + `aspsp_country`: `banking_connections`
 * has no bank_id and the `banks` table is per-user, so a bank UUID means nothing
 * outside the one account it belongs to.
 */
trait QueriesBankFailures
{
    /**
     * How many distinct people must have hit the bank before its failure can be
     * called the bank's. One user cancelling twice at their bank leaves the same
     * trace as a broken connector.
     */
    protected const MIN_AFFECTED_USERS = 2;

    /**
     * Every connection made through Enable Banking, which is the only provider
     * where a bank can break for everyone at once: the rest are API keys we hold
     * per user.
     */
    protected function matchesEnableBanking(): Closure
    {
        return fn (Builder $query) => $query->where('provider', BankingProvider::EnableBanking);
    }

    /**
     * Every Enable Banking connection to the bank the operator named.
     */
    protected function matchesBank(string $aspsp, ?string $country): Closure
    {
        return fn (Builder $query) => $query
            ->tap($this->matchesEnableBanking())
            ->where('aspsp_name', $aspsp)
            ->when($country, fn (Builder $query) => $query->where('aspsp_country', $country));
    }

    /**
     * Attempts the bank itself sent back as an error.
     *
     * A soft-deleted `pending` row is the fingerprint: AuthorizationController::
     * handleAuthorizationError() is the only path that deletes a connection while
     * it is still pending, and a manual disconnect sets Revoked before deleting.
     * A `pending` row that is *not* deleted means the user simply never came back
     * from the bank, which is not something to apologise for.
     */
    protected function failedAuthorizations(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($matchesBank)
            // Only the soft-deleted rows: a rejected callback deletes the
            // connection, so every attempt worth reporting is trashed. The column
            // is qualified because this also runs as a subquery against `users`,
            // which has a `deleted_at` of its own.
            ->withoutGlobalScope(SoftDeletingScope::class)
            ->whereNotNull('banking_connections.deleted_at')
            ->where('status', BankingConnectionStatus::Pending);
    }

    /**
     * The connections an outage at the bank actually explains: the ones the
     * scheduler will keep retrying, mirroring SyncAllBankingConnectionsJob.
     *
     * This is what keeps a claim about the bank honest. An expired, revoked or
     * retry-capped connection is stuck for its own reason and its owner does have
     * to reconnect, which is the opposite of a bank-side outage.
     */
    protected function matchesOutage(Closure $matchesBank): Closure
    {
        return fn (Builder $query) => $query
            ->tap($matchesBank)
            ->where(fn (Builder $query) => $query
                ->where('status', BankingConnectionStatus::Active)
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', BankingConnectionStatus::Error)
                    ->where('consecutive_sync_failures', '<', SyncBankingConnectionJob::MAX_SCHEDULED_RETRIES)))
            ->where(fn (Builder $query) => $query
                ->whereNull('valid_until')
                ->orWhere('valid_until', '>', now()));
    }

    /**
     * The ledger key for one bank, stable across re-runs.
     */
    protected function bankIdentifier(string $bankName, string $country): string
    {
        return Str::slug("{$bankName}-{$country}");
    }
}

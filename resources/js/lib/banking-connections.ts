import type { BankingConnection } from '@/types/banking';

/**
 * Statuses that count as a live connection. Only these block re-adding the same
 * bank: the connection is either usable (active) or freshly authorized and
 * awaiting account mapping. Pending (abandoned mid-flow), expired, revoked and
 * error connections never block, so the user can always start a fresh one.
 *
 * Soft-deleted connections never reach the frontend, so a deleted connection
 * never blocks either.
 */
const LIVE_STATUSES: ReadonlySet<BankingConnection['status']> = new Set([
    'active',
    'awaiting_mapping',
]);

function isLiveConnection(connection: BankingConnection): boolean {
    return LIVE_STATUSES.has(connection.status);
}

/**
 * Names of EnableBanking ASPSPs the user has a live connection to.
 */
export function alreadyConnectedBankNames(
    connections: BankingConnection[],
): Set<string> {
    return new Set(
        connections
            .filter(
                (c) => c.provider === 'enablebanking' && isLiveConnection(c),
            )
            .map((c) => c.aspsp_name),
    );
}

/**
 * Whether the user already has a live connection for a single-connection
 * provider (Binance, Bitpanda, Coinbase, Indexa Capital, …).
 */
export function hasLiveConnectionForProvider(
    connections: BankingConnection[],
    provider: string,
): boolean {
    return connections.some(
        (c) => c.provider === provider && isLiveConnection(c),
    );
}

/**
 * Whether a first sync is genuinely still running.
 *
 * A connection only gets a `last_synced_at` once a run finishes, so a
 * never-synced active connection normally means "syncing". Not once it has
 * recorded an error: a rate limit leaves the status Active and stores only the
 * message, so `last_synced_at` would stay null forever and the spinner with it.
 */
export function isFirstSyncRunning(connection: BankingConnection): boolean {
    return (
        connection.status === 'active' &&
        !connection.last_synced_at &&
        !connection.error_message
    );
}

/**
 * The other half of {@link isFirstSyncRunning}: the bank refused the first sync.
 * Not an error state - the transactions do import - only a wait for the access
 * window to reopen.
 */
export function isWaitingForBank(connection: BankingConnection): boolean {
    return (
        connection.status === 'active' &&
        !connection.last_synced_at &&
        !!connection.error_message
    );
}

/**
 * How many days before `valid_until` a consent counts as expiring soon. Mirrors
 * `BankingConnection::EXPIRY_WARNING_DAYS`, which drives the warning email.
 */
const EXPIRY_WARNING_DAYS = 7;

/**
 * Whether the consent is close enough to running out that the user should renew
 * it now, while syncing still works. Only EnableBanking connections carry a
 * `valid_until`, so nothing else is ever expiring soon; an already-lapsed one is
 * expired, not expiring.
 */
export function isExpiringSoon(connection: BankingConnection): boolean {
    if (connection.status !== 'active' || !connection.valid_until) {
        return false;
    }

    const msUntilExpiry =
        new Date(connection.valid_until).getTime() - Date.now();

    return (
        msUntilExpiry > 0 &&
        msUntilExpiry < EXPIRY_WARNING_DAYS * 24 * 60 * 60 * 1000
    );
}

/**
 * Whether the user may spend an access call on demand. Computed server-side from
 * the backoff, which is the enforcing side too; the flag is only present on the
 * connections page payload.
 */
export function canSyncManually(connection: BankingConnection): boolean {
    return connection.can_sync_manually !== false;
}

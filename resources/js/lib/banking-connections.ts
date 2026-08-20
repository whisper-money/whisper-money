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
 * Whether a connection's first sync is genuinely still running.
 *
 * "Active with nothing synced yet" is not enough on its own, and that was the
 * bug: a connection the bank has refused stays `active` with `last_synced_at`
 * still null, so it was indistinguishable from one that had just been
 * authorized. Fourteen connections in production sat in that state - twelve of
 * them Trade Republic, one created a month ago and never synced once - each
 * showing a spinner that would never resolve while the page asked the server
 * for news every five seconds.
 *
 * The thing that separates them is whether an attempt has already failed.
 * `error_message` holds that and survives the backoff window elapsing, unlike
 * `rate_limited_until`: a bare "Too many requests" gets a one-hour park against
 * a six-hourly schedule, so for five hours out of every six the park has lapsed
 * while the connection is no closer to working.
 */
export function isFirstSyncInProgress(connection: BankingConnection): boolean {
    return (
        connection.status === 'active' &&
        !connection.last_synced_at &&
        connection.error_message === null
    );
}

/**
 * Whether a connection has never delivered anything and its last attempt failed.
 */
export function isFirstSyncStalled(connection: BankingConnection): boolean {
    return (
        connection.status === 'active' &&
        !connection.last_synced_at &&
        connection.error_message !== null
    );
}

/**
 * Whether the bank's own retry window is still open, so a time can be named.
 *
 * Only ever used to decide whether to print one. Whether a connection is stuck
 * is `isFirstSyncStalled`'s question, because this one is false for most of the
 * time a connection spends stuck.
 */
export function isWaitingForBankQuota(
    connection: BankingConnection,
    now: Date = new Date(),
): boolean {
    if (connection.rate_limited_until === null) {
        return false;
    }

    return new Date(connection.rate_limited_until) > now;
}

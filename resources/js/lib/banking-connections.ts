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

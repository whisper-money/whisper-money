import type { BankingConnection } from '@/types/banking';
import { describe, expect, it } from 'vitest';
import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
    isExpiringSoon,
} from './banking-connections';

function connection(
    overrides: Partial<BankingConnection> = {},
): BankingConnection {
    return {
        id: crypto.randomUUID(),
        provider: 'enablebanking',
        aspsp_name: 'Bankinter',
        aspsp_country: 'ES',
        status: 'active',
        valid_until: null,
        last_synced_at: null,
        error_message: null,
        accounts_count: 1,
        created_at: '2026-01-01T00:00:00Z',
        updated_at: '2026-01-01T00:00:00Z',
        ...overrides,
    };
}

describe('alreadyConnectedBankNames', () => {
    it('includes live EnableBanking banks (active and awaiting_mapping)', () => {
        const names = alreadyConnectedBankNames([
            connection({ aspsp_name: 'Bankinter', status: 'active' }),
            connection({ aspsp_name: 'BBVA', status: 'awaiting_mapping' }),
        ]);

        expect(names).toEqual(new Set(['Bankinter', 'BBVA']));
    });

    it('excludes expired, revoked, error and pending so the bank can be re-added', () => {
        const names = alreadyConnectedBankNames([
            connection({ aspsp_name: 'Bankinter', status: 'pending' }),
            connection({ aspsp_name: 'BBVA', status: 'expired' }),
            connection({ aspsp_name: 'ING', status: 'revoked' }),
            connection({ aspsp_name: 'Santander', status: 'error' }),
        ]);

        expect(names).toEqual(new Set());
    });

    it('ignores non-EnableBanking providers', () => {
        const names = alreadyConnectedBankNames([
            connection({ provider: 'binance', aspsp_name: 'Binance' }),
        ]);

        expect(names.has('Binance')).toBe(false);
    });
});

describe('hasLiveConnectionForProvider', () => {
    it('is true only when a live connection for the provider exists', () => {
        const connections = [
            connection({ provider: 'binance', status: 'active' }),
        ];

        expect(hasLiveConnectionForProvider(connections, 'binance')).toBe(true);
        expect(hasLiveConnectionForProvider(connections, 'coinbase')).toBe(
            false,
        );
    });

    it('ignores non-live connections so the provider can be re-added', () => {
        const connections = [
            connection({ provider: 'binance', status: 'error' }),
            connection({ provider: 'coinbase', status: 'expired' }),
        ];

        expect(hasLiveConnectionForProvider(connections, 'binance')).toBe(
            false,
        );
        expect(hasLiveConnectionForProvider(connections, 'coinbase')).toBe(
            false,
        );
    });
});

describe('isExpiringSoon', () => {
    const inDays = (days: number) =>
        new Date(Date.now() + days * 24 * 60 * 60 * 1000).toISOString();

    it('is true inside the last week of the consent window', () => {
        expect(isExpiringSoon(connection({ valid_until: inDays(1) }))).toBe(
            true,
        );
        expect(isExpiringSoon(connection({ valid_until: inDays(6.9) }))).toBe(
            true,
        );
    });

    it('is false while the consent still has more than a week to run', () => {
        expect(isExpiringSoon(connection({ valid_until: inDays(7.1) }))).toBe(
            false,
        );
        expect(isExpiringSoon(connection({ valid_until: inDays(90) }))).toBe(
            false,
        );
    });

    it('is false once the consent has lapsed - that is expired, not expiring', () => {
        expect(isExpiringSoon(connection({ valid_until: inDays(-1) }))).toBe(
            false,
        );
        expect(
            isExpiringSoon(
                connection({ status: 'expired', valid_until: inDays(-1) }),
            ),
        ).toBe(false);
    });

    it('is false for connections that carry no consent at all', () => {
        expect(
            isExpiringSoon(
                connection({ provider: 'binance', valid_until: null }),
            ),
        ).toBe(false);
    });

    it('is false for a connection that is not active', () => {
        expect(
            isExpiringSoon(
                connection({ status: 'error', valid_until: inDays(2) }),
            ),
        ).toBe(false);
    });
});

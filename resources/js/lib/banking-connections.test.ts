import type { BankingConnection } from '@/types/banking';
import { describe, expect, it } from 'vitest';
import {
    alreadyConnectedBankNames,
    hasLiveConnectionForProvider,
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

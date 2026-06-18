import type { BankingConnection } from '@/types/banking';
import { describe, expect, it } from 'vitest';
import { alreadyConnectedBankNames } from './connect-account-dialog';

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
    it('includes active, error and expired EnableBanking banks', () => {
        const names = alreadyConnectedBankNames([
            connection({ aspsp_name: 'Bankinter', status: 'active' }),
            connection({ aspsp_name: 'BBVA', status: 'error' }),
            connection({ aspsp_name: 'ING', status: 'expired' }),
        ]);

        expect(names).toEqual(new Set(['Bankinter', 'BBVA', 'ING']));
    });

    it('excludes pending connections so a stale attempt does not block re-adding', () => {
        const names = alreadyConnectedBankNames([
            connection({ aspsp_name: 'Bankinter', status: 'pending' }),
        ]);

        expect(names.has('Bankinter')).toBe(false);
    });

    it('ignores non-EnableBanking providers', () => {
        const names = alreadyConnectedBankNames([
            connection({ provider: 'binance', aspsp_name: 'Binance' }),
        ]);

        expect(names.has('Binance')).toBe(false);
    });
});

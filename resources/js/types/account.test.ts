import { describe, expect, it } from 'vitest';
import { filterTransactionalAccounts } from './account';

describe('filterTransactionalAccounts', () => {
    it('drops archived accounts and the types without a ledger', () => {
        const accounts = [
            { id: 'checking', type: 'checking' as const },
            {
                id: 'archived',
                type: 'checking' as const,
                archived_at: '2026-08-01T00:00:00.000000Z',
            },
            { id: 'real-estate', type: 'real_estate' as const },
        ];

        expect(filterTransactionalAccounts(accounts).map((a) => a.id)).toEqual([
            'checking',
        ]);
    });
});

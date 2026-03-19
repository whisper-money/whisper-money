import { describe, expect, it } from 'vitest';

import {
    deriveAccountMetrics,
    type NetWorthEvolutionData,
} from './use-dashboard-data';

describe('deriveAccountMetrics', () => {
    it('returns loan balances and diffs as negative net worth contributions', () => {
        const netWorthEvolution: NetWorthEvolutionData = {
            currency_code: 'EUR',
            accounts: {
                loan_1: {
                    id: 'loan_1',
                    name: 'Mortgage',
                    name_iv: null,
                    encrypted: false,
                    type: 'loan',
                    currency_code: 'EUR',
                    bank: {
                        id: 'bank_1',
                        user_id: null,
                        name: 'Bank',
                        logo: null,
                    },
                    banking_connection_id: null,
                },
            },
            data: [
                { month: '2025-01', loan_1: 120000 },
                { month: '2025-02', loan_1: 100000 },
            ],
        };

        const [account] = deriveAccountMetrics(netWorthEvolution, 'en-US');

        expect(account.currentBalance).toBe(-100000);
        expect(account.previousBalance).toBe(-120000);
        expect(account.diff).toBe(20000);
        expect(account.history).toEqual([
            expect.objectContaining({ value: -120000 }),
            expect.objectContaining({ value: -100000 }),
        ]);
    });
});

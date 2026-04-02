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

    it('preserves original account currency_code even when net worth uses a different user currency', () => {
        const netWorthEvolution: NetWorthEvolutionData = {
            currency_code: 'EUR',
            accounts: {
                btc_1: {
                    id: 'btc_1',
                    name: 'Bitcoin Wallet',
                    name_iv: null,
                    encrypted: false,
                    type: 'investment',
                    currency_code: 'BTC',
                    bank: {
                        id: 'bank_1',
                        user_id: null,
                        name: 'Crypto',
                        logo: null,
                    },
                    banking_connection_id: null,
                },
                eur_1: {
                    id: 'eur_1',
                    name: 'Savings',
                    name_iv: null,
                    encrypted: false,
                    type: 'savings',
                    currency_code: 'EUR',
                    bank: {
                        id: 'bank_2',
                        user_id: null,
                        name: 'Bank',
                        logo: null,
                    },
                    banking_connection_id: null,
                },
            },
            data: [
                { month: '2025-01', btc_1: 4000000, eur_1: 500000 },
                { month: '2025-02', btc_1: 5000000, eur_1: 600000 },
            ],
        };

        const accounts = deriveAccountMetrics(netWorthEvolution, 'en-US');
        const btcAccount = accounts.find((a) => a.id === 'btc_1')!;
        const eurAccount = accounts.find((a) => a.id === 'eur_1')!;

        // currency_code on derived metrics preserves the original account currency
        // (the UI layer uses displayCurrencyCode from netWorthEvolution.currency_code to fix display)
        expect(btcAccount.currency_code).toBe('BTC');
        expect(eurAccount.currency_code).toBe('EUR');

        // Balances are the converted amounts from the net worth evolution data
        expect(btcAccount.currentBalance).toBe(5000000);
        expect(btcAccount.previousBalance).toBe(4000000);
        expect(btcAccount.diff).toBe(1000000);
        expect(eurAccount.currentBalance).toBe(600000);
        expect(eurAccount.previousBalance).toBe(500000);
    });

    it('returns empty array when data or accounts are empty', () => {
        expect(
            deriveAccountMetrics(
                { currency_code: 'USD', accounts: {}, data: [] },
                'en-US',
            ),
        ).toEqual([]);

        expect(
            deriveAccountMetrics(
                {
                    currency_code: 'USD',
                    accounts: {},
                    data: [{ month: '2025-01' }],
                },
                'en-US',
            ),
        ).toEqual([]);
    });

    it('includes invested amount data when present', () => {
        const netWorthEvolution: NetWorthEvolutionData = {
            currency_code: 'USD',
            accounts: {
                inv_1: {
                    id: 'inv_1',
                    name: 'Portfolio',
                    name_iv: null,
                    encrypted: false,
                    type: 'investment',
                    currency_code: 'USD',
                    bank: {
                        id: 'bank_1',
                        user_id: null,
                        name: 'Broker',
                        logo: null,
                    },
                    banking_connection_id: null,
                    invested_amount: 300000,
                },
            },
            data: [
                {
                    month: '2025-01',
                    inv_1: 400000,
                    inv_1_invested: 250000,
                },
                {
                    month: '2025-02',
                    inv_1: 500000,
                    inv_1_invested: 300000,
                },
            ],
        };

        const [account] = deriveAccountMetrics(netWorthEvolution, 'en-US');

        expect(account.investedAmount).toBe(300000);
        expect(account.history[0].investedAmount).toBe(250000);
        expect(account.history[1].investedAmount).toBe(300000);
    });
});

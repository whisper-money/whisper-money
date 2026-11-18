import {
    cashBalances,
    computedCashBalance,
    dailyInvested,
} from '@/lib/broker-imports/series';
import { type CashMovement, type Trade } from '@/lib/broker-imports/types';
import { describe, expect, it } from 'vitest';

function trade(date: string, side: Trade['side'], amount: number): Trade {
    return {
        date,
        side,
        amount,
        symbol: 'X',
        name: 'X',
        assetClass: null,
        shares: 1,
        currency: 'EUR',
        externalId: date,
        isTransfer: false,
    };
}

function cash(date: string, amount: number): CashMovement {
    return {
        date,
        amount,
        currency: 'EUR',
        description: '',
        externalId: `${date}${amount}`,
        kind: 'other',
    };
}

describe('dailyInvested', () => {
    it('has one cumulative point per day from the first trade to today', () => {
        expect(
            dailyInvested(
                [
                    trade('2026-03-02', 'sell', 300),
                    trade('2026-02-27', 'buy', 1000),
                    trade('2026-03-02', 'buy', 50),
                ],
                '2026-03-03',
            ),
        ).toEqual([
            { date: '2026-02-27', investedMinor: 1000 },
            { date: '2026-02-28', investedMinor: 1000 },
            { date: '2026-03-01', investedMinor: 1000 },
            { date: '2026-03-02', investedMinor: 750 },
            { date: '2026-03-03', investedMinor: 750 },
        ]);
    });

    it('leaves transfers between funds out', () => {
        const out = { ...trade('2026-03-01', 'sell', 600), isTransfer: true };
        const into = { ...trade('2026-03-02', 'buy', 610), isTransfer: true };

        expect(
            dailyInvested(
                [trade('2026-03-01', 'buy', 1000), out, into],
                '2026-03-02',
            ),
        ).toEqual([
            { date: '2026-03-01', investedMinor: 1000 },
            { date: '2026-03-02', investedMinor: 1000 },
        ]);
    });

    it('is empty without trades', () => {
        expect(dailyInvested([], '2026-03-03')).toEqual([]);
    });
});

describe('cashBalances', () => {
    it('rebuilds each end-of-day balance backwards from today', () => {
        expect(
            cashBalances(
                [
                    cash('2026-01-01', 100),
                    cash('2026-01-03', -30),
                    cash('2026-01-03', 5),
                ],
                1000,
                '2026-01-04',
            ),
        ).toEqual([
            { date: '2026-01-01', balanceMinor: 1025 },
            { date: '2026-01-02', balanceMinor: 1025 },
            { date: '2026-01-03', balanceMinor: 1000 },
            { date: '2026-01-04', balanceMinor: 1000 },
        ]);
    });

    it('leaves movements dated after today out', () => {
        expect(
            cashBalances(
                [cash('2026-01-01', 100), cash('2026-01-09', -30)],
                100,
                '2026-01-02',
            ),
        ).toEqual([
            { date: '2026-01-01', balanceMinor: 100 },
            { date: '2026-01-02', balanceMinor: 100 },
        ]);
    });

    it('is empty without movements', () => {
        expect(cashBalances([], 100, '2026-01-02')).toEqual([]);
    });
});

describe('computedCashBalance', () => {
    it('adds every movement up', () => {
        expect(
            computedCashBalance([
                cash('2026-01-01', 100),
                cash('2026-01-02', -30),
            ]),
        ).toBe(70);
        expect(computedCashBalance([])).toBe(0);
    });
});

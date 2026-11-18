import { parseMyInvestorSecurities } from '@/lib/broker-imports/myinvestor-securities';
import { dailyInvested } from '@/lib/broker-imports/series';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { describe, expect, it } from 'vitest';

describe('parseMyInvestorSecurities', () => {
    it.each(['myinvestor-salida.xls', 'myinvestor-salida.xlsx'])(
        'reads %s anchored on each ISIN',
        async (name) => {
            const file = parseMyInvestorSecurities(await fixtureGrid(name));

            expect(
                file.trades.map((one) => [
                    one.date,
                    one.side,
                    one.symbol,
                    one.shares,
                    one.amount,
                    one.isTransfer,
                ]),
            ).toEqual([
                ['2026-01-10', 'buy', 'IE0000000101', 10.5, 105000, false],
                ['2026-02-10', 'buy', 'IE0000000101', 2, 22000, false],
                ['2026-03-01', 'sell', 'IE0000000101', 5, 60000, true],
                ['2026-03-01', 'buy', 'ES0000000202', 60, 60000, true],
                ['2026-03-15', 'buy', 'ES0000000303', 20, 30000, false],
                ['2026-04-10', 'sell', 'ES0000000303', 10, 16000, false],
                ['2026-05-02', 'sell', 'IE0000000101', 7.5, 90000, true],
                ['2026-05-02', 'buy', 'LU0000000404', 90, 90000, true],
            ]);
            expect(file.trades[3]).toMatchObject({
                name: 'FONDO DEMO RENTA FIJA ESPAÑA',
                assetClass: 'FUND',
            });
            expect(file.trades[4].assetClass).toBe('STOCK');
            expect(file.dividends).toEqual([
                expect.objectContaining({
                    date: '2026-04-01',
                    symbol: 'ES0000000303',
                    amount: 500,
                }),
            ]);
            expect(file.unassigned).toEqual([
                expect.objectContaining({
                    date: '2026-06-01',
                    side: 'buy',
                    symbol: 'N0001',
                    assetClass: 'PENSION',
                    amount: 10000,
                }),
            ]);
        },
    );

    it('nets transfers between funds out of what was invested', async () => {
        const file = parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xls'),
        );
        const invested = dailyInvested(
            [...file.trades, ...file.unassigned],
            '2026-06-01',
        );
        const on = (date: string) =>
            invested.find((one) => one.date === date)?.investedMinor;

        expect(on('2026-02-28')).toBe(127000);
        expect(on('2026-03-01')).toBe(127000);
        expect(on('2026-05-02')).toBe(141000);
        expect(on('2026-06-01')).toBe(151000);
    });

    it('gives every order its own id, the same on every read', async () => {
        const first = parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xls'),
        );
        const second = parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xlsx'),
        );
        const ids = (file: typeof first) =>
            [...file.trades, ...file.unassigned, ...file.dividends].map(
                (one) => one.externalId,
            );

        expect(new Set(ids(first)).size).toBe(10);
        expect(ids(second)).toEqual(ids(first));
    });

    it('tells repeated identical orders apart', () => {
        const order = [
            '2026-01-10',
            '2026-01-12',
            '150388401',
            'FONDOS EXTRANJEROS',
            'SUSCRIPCION',
            'IE0000000101',
            'FONDO DEMO',
            '1',
            'EUR',
            '10,00',
            '10,00',
        ];
        const { trades } = parseMyInvestorSecurities([order, order]);

        expect(trades).toHaveLength(2);
        expect(trades[0].externalId).not.toBe(trades[1].externalId);
    });

    it('sums positions, transfers carrying their average cost across', async () => {
        const { positions } = parseMyInvestorSecurities(
            await fixtureGrid('myinvestor-salida.xls'),
        );

        expect(
            positions.map((one) => [one.symbol, one.shares, one.invested]),
        ).toEqual([
            ['IE0000000101', 0, 0],
            ['ES0000000202', 60, 50800],
            ['ES0000000303', 10, 14000],
            ['LU0000000404', 90, 76200],
            ['N0001', 3, 10000],
        ]);
    });
});

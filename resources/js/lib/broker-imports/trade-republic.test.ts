import { fixtureGrid } from '@/lib/broker-imports/testing';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import { describe, expect, it } from 'vitest';

async function readSample() {
    return parseTradeRepublic(await fixtureGrid('trade-republic-sample.csv'));
}

function movement(
    file: Awaited<ReturnType<typeof readSample>>,
    idSuffix: string,
) {
    const found = file.cash.find((one) =>
        one.externalId.endsWith(`-0000000000${idSuffix}`),
    );

    expect(found).toBeDefined();

    return found!;
}

describe('parseTradeRepublic', () => {
    it('turns every row that moves cash into a movement, trades included', async () => {
        const file = await readSample();

        // 23 rows; the crypto FREE_RECEIPT moves no cash.
        expect(file.cash).toHaveLength(22);
        expect(file.cash.every((one) => one.currency === 'EUR')).toBe(true);
        expect(new Set(file.cash.map((one) => one.externalId)).size).toBe(22);
    });

    it('nets fees and taxes into the amount', async () => {
        const file = await readSample();

        expect(movement(file, '01')).toMatchObject({
            amount: 5000,
            kind: 'transfer_in',
        });
        expect(movement(file, '05').amount).toBe(-5406);
        expect(movement(file, '11')).toMatchObject({
            amount: 26,
            kind: 'interest',
        });
    });

    it('keeps fee-only and tax-only rows', async () => {
        const file = await readSample();

        expect(movement(file, '15')).toMatchObject({
            amount: -500,
            kind: 'fee',
        });
        expect(movement(file, '14')).toMatchObject({
            amount: 1533,
            kind: 'tax',
        });
        expect(movement(file, '19')).toMatchObject({ amount: -8, kind: 'tax' });
    });

    it('imports a corrected dividend as-is, netting to zero', async () => {
        const file = await readSample();
        const dividend = movement(file, '09');
        const correction = movement(file, '10');

        expect(dividend).toMatchObject({
            amount: 2202,
            kind: 'dividend',
            isin: 'US0000000001',
            description: 'Dividend · Test Net Lease',
        });
        expect(dividend.amount + correction.amount).toBe(0);
    });

    it('describes card payments by merchant, foreign ones without the FX details', async () => {
        const file = await readSample();

        expect(movement(file, '04')).toMatchObject({
            description: 'Test Merchant 01',
            kind: 'card',
        });
        expect(movement(file, '05')).toMatchObject({
            description: 'TEST SHOP BALI',
            kind: 'card',
        });
        expect(movement(file, '21')).toMatchObject({
            amount: 1240,
            kind: 'card',
        });
    });

    it('labels each kind', async () => {
        const file = await readSample();

        expect(movement(file, '06')).toMatchObject({
            kind: 'buy',
            description: 'Buy · Apple',
            amount: -669,
        });
        expect(movement(file, '07')).toMatchObject({
            kind: 'sell',
            description: 'Sell · Apple',
            amount: 458,
        });
        expect(movement(file, '12')).toMatchObject({
            kind: 'saveback',
            description: 'Saveback',
        });
        expect(movement(file, '13')).toMatchObject({
            kind: 'transfer_out',
            description: 'Outgoing transfer',
        });
        expect(movement(file, '03')).toMatchObject({
            kind: 'transfer_in',
            description: 'CUSTOMER_INBOUND',
        });
        expect(movement(file, '17')).toMatchObject({
            kind: 'direct_debit',
            description: 'Test Energy SA',
        });
        expect(movement(file, '18')).toMatchObject({
            kind: 'bonus',
            description: 'Welcome bonus',
        });
        expect(movement(file, '23')).toMatchObject({
            kind: 'transfer_out',
            amount: -2000,
        });
    });

    it('reads an unknown type as other, signed by its amount', async () => {
        const file = await readSample();

        expect(movement(file, '22')).toMatchObject({
            kind: 'other',
            amount: -300,
            description: 'Something new',
        });
    });

    it('lists buys and sells as trades, savings plans and crypto included', async () => {
        const { trades } = await readSample();

        expect(trades).toEqual([
            expect.objectContaining({
                date: '2026-01-15',
                side: 'buy',
                symbol: 'US0378331005',
                name: 'Apple',
                assetClass: 'STOCK',
                shares: 0.223214,
                amount: 669,
            }),
            expect.objectContaining({
                date: '2026-01-20',
                side: 'sell',
                symbol: 'US0378331005',
                shares: 0.223214,
                amount: 458,
            }),
            expect.objectContaining({
                date: '2026-02-01',
                side: 'buy',
                symbol: 'BTC',
                assetClass: 'CRYPTO',
                shares: 0.000849,
                amount: 4997,
            }),
            expect.objectContaining({
                date: '2026-02-25',
                side: 'buy',
                symbol: 'IE0000000002',
                assetClass: 'FUND',
                shares: 1.5,
                amount: 15000,
            }),
        ]);
    });

    it('sums positions per symbol, counting crypto received for free', async () => {
        const { positions } = await readSample();

        expect(positions).toEqual([
            expect.objectContaining({
                symbol: 'US0378331005',
                shares: 0,
                invested: 211,
            }),
            expect.objectContaining({
                symbol: 'BTC',
                shares: 0.000849,
                invested: 4997,
            }),
            expect.objectContaining({
                symbol: 'ETH',
                shares: 0.000491,
                invested: 0,
            }),
            expect.objectContaining({
                symbol: 'IE0000000002',
                shares: 1.5,
                invested: 15000,
            }),
        ]);
    });

    it('reads the older semicolon layout', async () => {
        const file = parseTradeRepublic(
            await fixtureGrid('trade-republic-legacy.csv'),
        );

        expect(
            file.cash.map((one) => [one.date, one.amount, one.kind]),
        ).toEqual([
            ['2024-05-02', 100000, 'transfer_in'],
            ['2024-05-15', -20100, 'buy'],
            ['2024-05-20', -725, 'card'],
        ]);
        expect(file.positions).toEqual([
            expect.objectContaining({
                symbol: 'US0000000003',
                shares: 2,
                invested: 20100,
            }),
        ]);
    });

    it('reads a row repeated by overlapping exports once', async () => {
        const grid = await fixtureGrid('trade-republic-legacy.csv');
        const file = parseTradeRepublic([...grid, grid[1], grid[3]]);

        expect(file.cash).toHaveLength(3);
    });

    it('reads nothing from a file without the header', () => {
        expect(parseTradeRepublic([['a', 'b']])).toEqual({
            cash: [],
            trades: [],
            positions: [],
        });
    });
});

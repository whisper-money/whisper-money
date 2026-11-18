import { parseMyInvestorCash } from '@/lib/broker-imports/myinvestor-cash';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { describe, expect, it } from 'vitest';

describe('parseMyInvestorCash', () => {
    it('reads the semicolon CSV, accents and Spanish numbers included', async () => {
        const { movements, hasBalance } = parseMyInvestorCash(
            await fixtureGrid('myinvestor-cash.csv'),
        );

        expect(hasBalance).toBe(false);
        expect(movements).toHaveLength(13);
        expect(movements[0]).toMatchObject({
            date: '2026-10-02',
            amount: 1248,
            currency: 'EUR',
            description: 'PERIODO 01/09/2026 - 30/09/2026',
            kind: 'interest',
        });
        expect(movements[1]).toMatchObject({
            amount: 185000,
            description: 'Transferencia recibida de EMPRESA DEMO SL - Nómina',
            kind: 'transfer_in',
        });
        expect(movements.map((one) => one.kind)).toEqual([
            'interest',
            'transfer_in',
            'card',
            'card',
            'transfer_in',
            'transfer_out',
            'transfer_out',
            'buy',
            'tax',
            'card',
            'other',
            'other',
            'transfer_in',
        ]);
        expect(movements.some((one) => 'balance' in one)).toBe(false);
    });

    it('finds the header below the xlsx title rows and reads the balance column', async () => {
        const { movements, hasBalance } = parseMyInvestorCash(
            await fixtureGrid('myinvestor-cash.xlsx'),
        );

        expect(hasBalance).toBe(true);
        expect(
            movements.map((one) => [one.date, one.amount, one.balance]),
        ).toEqual([
            ['2026-10-02', 1248, 101248],
            ['2026-10-03', -6235, 95013],
            ['2026-10-03', -6235, 88778],
            ['2026-10-05', 185000, 273778],
            ['2026-10-10', -150322, 123456],
        ]);
    });

    it('gives identical rows of one file their own ids, the same on every read', async () => {
        const first = parseMyInvestorCash(
            await fixtureGrid('myinvestor-cash.xlsx'),
        );
        const second = parseMyInvestorCash(
            await fixtureGrid('myinvestor-cash.xlsx'),
        );
        const [, coffee, sameCoffee] = first.movements;

        expect(coffee.externalId).not.toBe(sameCoffee.externalId);
        expect(coffee.externalId.replace(/-0$/, '')).toBe(
            sameCoffee.externalId.replace(/-1$/, ''),
        );
        expect(second.movements.map((one) => one.externalId)).toEqual(
            first.movements.map((one) => one.externalId),
        );
        expect(new Set(first.movements.map((one) => one.externalId)).size).toBe(
            5,
        );
    });

    it('reads dates written as spreadsheet serials and skips footers', () => {
        const { movements } = parseMyInvestorCash([
            ['Resumen de movimientos'],
            ['Fecha', 'Concepto', 'Importe', 'Divisa'],
            [46297, 'Compra con tarjeta DEMO', -10, 'USD'],
            ['Total', '', '', ''],
        ]);

        expect(movements).toEqual([
            expect.objectContaining({
                date: '2026-10-02',
                amount: -1000,
                currency: 'USD',
                kind: 'card',
            }),
        ]);
    });

    it('leaves a generic "Fecha, Concepto, Importe" file alone', () => {
        expect(
            parseMyInvestorCash([
                ['Fecha', 'Concepto', 'Importe'],
                ['02/10/2026', 'Café', '-2,50'],
            ]).movements,
        ).toEqual([]);
    });

    it('reads nothing from a file without the header', () => {
        expect(parseMyInvestorCash([['Date', 'Amount']])).toEqual({
            movements: [],
            hasBalance: false,
        });
    });
});

import { detectBrokerFile } from '@/lib/broker-imports/detect';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { describe, expect, it } from 'vitest';

describe('detectBrokerFile', () => {
    it.each([
        ['trade-republic-sample.csv', 'trade_republic'],
        ['trade-republic-legacy.csv', 'trade_republic'],
        ['myinvestor-cash.csv', 'myinvestor_cash'],
        ['myinvestor-cash.xlsx', 'myinvestor_cash'],
        ['myinvestor-salida.xls', 'myinvestor_securities'],
        ['myinvestor-salida.xlsx', 'myinvestor_securities'],
    ])('recognises %s', async (name, kind) => {
        expect(detectBrokerFile(await fixtureGrid(name))).toBe(kind);
    });

    it.each(['banktrack-sample.csv', 'money-pro-sample.csv'])(
        'leaves %s to the other importers',
        async (name) => {
            expect(detectBrokerFile(await fixtureGrid(name))).toBeNull();
        },
    );

    it('does not take a generic bank file for a broker', () => {
        expect(
            detectBrokerFile([
                ['Date', 'Description', 'Amount'],
                ['2026-01-02', 'Coffee', '-2.50'],
            ]),
        ).toBeNull();
        expect(detectBrokerFile([])).toBeNull();
    });

    it('needs every column of a Trade Republic header', () => {
        expect(
            detectBrokerFile([
                [
                    'datetime',
                    'date',
                    'category',
                    'type',
                    'amount',
                    'transaction_id',
                ],
            ]),
        ).toBeNull();
    });

    it('finds the MyInvestor header below title rows, but not past the scan window', () => {
        const titles = Array.from({ length: 15 }, () => [
            'Resumen de movimientos',
        ]);

        expect(
            detectBrokerFile([
                ...titles.slice(0, 3),
                ['Fecha', 'Movimiento', 'Importe'],
            ]),
        ).toBe('myinvestor_cash');
        expect(
            detectBrokerFile([...titles, ['Fecha', 'Concepto', 'Importe']]),
        ).toBeNull();
    });
});

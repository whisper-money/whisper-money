import { detectBrokerFile } from '@/lib/broker-imports/detect';
import { fixtureGrid } from '@/lib/broker-imports/testing';
import { describe, expect, it } from 'vitest';

describe('detectBrokerFile', () => {
    it.each([
        ['trade-republic-sample.csv', 'trade_republic'],
        ['trade-republic-legacy.csv', 'trade_republic'],
        ['myinvestor-cash.csv', 'myinvestor_cash'],
        ['myinvestor-cash.xlsx', 'myinvestor_cash'],
        ['myinvestor-cash-account.xlsx', 'myinvestor_cash'],
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

    it('takes a date, concept and amount header for MyInvestor only with its title rows above', () => {
        const header = ['Fecha', 'Movimiento', 'Importe', 'Saldo'];
        const row = [
            '02/10/2026',
            'Compra con tarjeta DEMO',
            '-10,00',
            '90,00',
        ];

        expect(detectBrokerFile([header, row])).toBeNull();
        expect(
            detectBrokerFile([['Banco Demo'], ['Extracto'], header, row]),
        ).toBeNull();
        expect(
            detectBrokerFile([['', 'Resumen de movimientos'], [], header, row]),
        ).toBe('myinvestor_cash');
        expect(
            detectBrokerFile([
                ['Cuenta', 'ES12 1544 7889 0000 0000 0001'],
                header,
                row,
            ]),
        ).toBe('myinvestor_cash');
    });

    it('takes the account template header for MyInvestor only below its holder and account labels', () => {
        const labels = [
            ['', '', '', 'TITULAR:', 'JANE DEMO'],
            ['', '', '', 'CUENTA:', '0000000001'],
        ];
        const header = [
            '',
            'Fecha Operación',
            'Fecha Valor',
            'Movimiento',
            '',
            'Importe',
            'Saldo',
        ];
        const row = ['', '02/10/2026', '02/10/2026', 'Café', '', -2.5, 97.5];

        expect(detectBrokerFile([...labels, header, row])).toBe(
            'myinvestor_cash',
        );
        expect(detectBrokerFile([labels[0], header, row])).toBeNull();
        expect(
            detectBrokerFile([
                ['Banco Demo'],
                [
                    'Fecha Operación',
                    'Fecha Valor',
                    'Concepto',
                    'Importe',
                    'Saldo',
                ],
                row,
            ]),
        ).toBeNull();
        expect(
            detectBrokerFile([
                ...labels,
                ['Fecha', 'Movimiento', 'Importe', 'Saldo'],
                row,
            ]),
        ).toBeNull();
    });

    it('does not look for the MyInvestor header past the scan window', () => {
        const titles = Array.from({ length: 15 }, () => [
            'Resumen de movimientos',
        ]);

        expect(
            detectBrokerFile([...titles, ['Fecha', 'Concepto', 'Importe']]),
        ).toBeNull();
    });

    it('needs the Inversis order number and dates around an ISIN to call it a securities export', () => {
        expect(
            detectBrokerFile([
                ['Tipo', 'ISIN', 'Nombre', 'Importe'],
                ['COMPRA', 'IE0000000101', 'FONDO DEMO', '100,00'],
            ]),
        ).toBeNull();
    });
});

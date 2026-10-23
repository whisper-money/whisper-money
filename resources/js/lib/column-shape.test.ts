import { describe, expect, it } from 'vitest';
import { columnShape, describeColumns } from './column-shape';

describe('columnShape', () => {
    it.each([
        [['15/03/2025', '02/04/2025'], 'date (dd/mm/yyyy)'],
        [['03/15/2025', '04/02/2025'], 'date (mm/dd/yyyy)'],
        [['01.02.2025', '03.04.2025'], 'date (dd.mm or mm.dd.yyyy)'],
        [['2025-03-15', '2025-04-02'], 'date (yyyy-mm-dd)'],
        [
            ['2025-03-15 10:22:01', '2025-04-02 08:00:00'],
            'date (yyyy-mm-dd with time)',
        ],
        [['15-03-25', '20-04-25'], 'date (dd-mm-yy)'],
        [['20250315', '20250402'], 'date (yyyymmdd)'],
        [['15 Mar 2025', '2 Apr 2025'], 'date (with month name)'],
        [['45731', '45750'], 'date (spreadsheet serial number)'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it.each([
        [
            ['-23,45', '1.850,00', '-9,99'],
            'number, 2 decimals with ",", mixed signs',
        ],
        [['23.45', '1,850.00'], 'number, 2 decimals with ".", all positive'],
        [['-23.45', '-9.99'], 'number, 2 decimals with ".", all negative'],
        [
            ['€ -23,45', '1.850,00 €'],
            'number, 2 decimals with ",", with currency symbol, mixed signs',
        ],
        [['(23.45)', '12.00'], 'number, 2 decimals with ".", mixed signs'],
        [['12', '-3'], 'integer, mixed signs'],
        [['100231', '845512', '312908'], 'integer id-like'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it.each([
        [['10:22:01', '18:03'], 'time'],
        [['EUR', 'USD'], 'three-letter uppercase code'],
        [['ES7621000418401234567891', 'NL91 ABNA 0417 1643 00'], 'IBAN'],
        [[], 'empty'],
    ])('reads %j as %s', (values, shape) => {
        expect(columnShape(values)).toBe(shape);
    });

    it('describes text by length and how much it repeats', () => {
        expect(columnShape(['Af', 'Bij', 'Af', 'Af'])).toBe(
            'text, avg 2 chars, 2 distinct values',
        );
        expect(columnShape(['Pago', 'Pago'])).toBe(
            'text, avg 4 chars, same value repeated',
        );
        expect(columnShape(['MERCADONA', 'NETFLIX.COM', 'AMAZON EU'])).toBe(
            'text, avg 10 chars, mostly distinct values',
        );
    });
});

describe('describeColumns', () => {
    const rows = [
        {
            Buchungstag: '01.03.2025',
            Verwendungszweck: 'REWE Markt Berlin',
            Betrag: '-12,40',
        },
        {
            Buchungstag: '15.03.2025',
            Verwendungszweck: 'Gehalt März',
            Betrag: '2.400,00',
        },
        { Buchungstag: '20.03.2025', Verwendungszweck: '', Betrag: '-5,00' },
    ];

    it('describes every column by header and shape, in file order', () => {
        expect(
            describeColumns(
                ['Buchungstag', 'Verwendungszweck', 'Betrag'],
                rows,
            ),
        ).toEqual([
            { header: 'Buchungstag', shape: 'date (dd.mm.yyyy)' },
            {
                header: 'Verwendungszweck',
                shape: 'text, avg 14 chars, mostly distinct values',
            },
            {
                header: 'Betrag',
                shape: 'number, 2 decimals with ",", mixed signs',
            },
        ]);
    });

    it('never carries a cell value', () => {
        const described = JSON.stringify(
            describeColumns(
                ['Buchungstag', 'Verwendungszweck', 'Betrag'],
                rows,
            ),
        );

        for (const row of rows) {
            for (const value of Object.values(row).filter(Boolean)) {
                expect(described).not.toContain(value);
            }
        }
    });

    it('works the shape out from a bounded sample of a long file', () => {
        const long = Array.from({ length: 5000 }, (_, index) => ({
            Amount: String(index % 2 === 0 ? -index : index),
        }));

        expect(describeColumns(['Amount'], long)[0].shape).toBe(
            'integer, mixed signs',
        );
    });
});

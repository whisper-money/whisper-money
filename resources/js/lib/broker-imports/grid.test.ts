import { parseDay, parseSpanishNumber } from '@/lib/broker-imports/grid';
import { rowIdFactory, stableHash } from '@/lib/broker-imports/ids';
import { gridFromColumns } from '@/lib/broker-imports/read';
import { describe, expect, it } from 'vitest';

describe('parseSpanishNumber', () => {
    it.each([
        ['1.234,56', 1234.56],
        ['-62,35', -62.35],
        ['1.850', 1850],
        ['-1.503.220', -1503220],
        ['12', 12],
        [-7.5, -7.5],
    ])('reads %s', (cell, expected) => {
        expect(parseSpanishNumber(cell)).toBe(expected);
    });

    it('returns null for an empty cell', () => {
        expect(parseSpanishNumber('')).toBeNull();
        expect(parseSpanishNumber(null)).toBeNull();
    });
});

describe('parseDay', () => {
    it.each([
        ['2026-03-01', '2026-03-01'],
        ['01/03/2026', '2026-03-01'],
        [46297, '2026-10-02'],
    ])('reads %s', (cell, expected) => {
        expect(parseDay(cell)).toBe(expected);
    });

    it('returns null for anything else', () => {
        expect(parseDay('Total')).toBeNull();
        expect(parseDay(undefined)).toBeNull();
    });
});

describe('stableHash', () => {
    it('is stable and tells texts apart', () => {
        expect(stableHash('2026-10-03|-6235|cafe')).toBe(
            stableHash('2026-10-03|-6235|cafe'),
        );
        expect(stableHash('2026-10-03|-6235|cafe')).not.toBe(
            stableHash('2026-10-03|-6236|cafe'),
        );
    });
});

describe('rowIdFactory', () => {
    it('numbers identical rows and keeps others apart', () => {
        const idOf = rowIdFactory('demo');
        const coffee = idOf(['2026-10-03', -250, 'cafe']);
        const sameCoffee = idOf(['2026-10-03', -250, 'cafe']);

        expect(coffee).toMatch(/^demo-\w+-0$/);
        expect(sameCoffee).toBe(coffee.replace(/-0$/, '-1'));
        expect(idOf(['2026-10-03', -251, 'cafe'])).toMatch(/-0$/);
    });
});

describe('gridFromColumns', () => {
    it('turns columns back into rows', () => {
        expect(gridFromColumns([['a', 1], ['b']])).toEqual([
            ['a', 'b'],
            [1, undefined],
        ]);
        expect(gridFromColumns([])).toEqual([]);
    });
});

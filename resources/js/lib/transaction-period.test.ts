import { formatLocalDate, toLocalDate } from '@/utils/date';
import { describe, expect, it } from 'vitest';
import { periodLabel, stepPeriod } from './transaction-period';

const day = (value: string) => toLocalDate(value);

function stepped(
    from: string | null,
    to: string | null,
    direction: -1 | 1,
): [string, string] | null {
    const range = stepPeriod(
        from ? day(from) : null,
        to ? day(to) : null,
        direction,
        day('2026-10-27'),
    );

    return range
        ? [formatLocalDate(range.from), formatLocalDate(range.to)]
        : null;
}

describe('stepPeriod', () => {
    it('steps a whole month to the month before or after', () => {
        expect(stepped('2026-10-01', '2026-10-31', -1)).toEqual([
            '2026-09-01',
            '2026-09-30',
        ]);
        expect(stepped('2026-12-01', '2026-12-31', 1)).toEqual([
            '2027-01-01',
            '2027-01-31',
        ]);
    });

    it('steps a quarter and a year by their own unit', () => {
        expect(stepped('2026-10-01', '2026-12-31', -1)).toEqual([
            '2026-07-01',
            '2026-09-30',
        ]);
        expect(stepped('2026-01-01', '2026-12-31', 1)).toEqual([
            '2027-01-01',
            '2027-12-31',
        ]);
    });

    it('moves any other range by its own length', () => {
        expect(stepped('2026-10-01', '2026-10-10', 1)).toEqual([
            '2026-10-11',
            '2026-10-20',
        ]);
    });

    it('lands on the current month from no date filter, either way', () => {
        expect(stepped(null, null, -1)).toEqual(['2026-10-01', '2026-10-31']);
        expect(stepped(null, null, 1)).toEqual(['2026-10-01', '2026-10-31']);
    });

    it('has nothing to step by with only one end set', () => {
        expect(stepped('2026-10-01', null, 1)).toBeNull();
        expect(stepped(null, '2026-10-01', -1)).toBeNull();
    });
});

describe('periodLabel', () => {
    it('names a month, a quarter and a year', () => {
        expect(periodLabel(day('2026-10-01'), day('2026-10-31'), 'en-US')).toBe(
            'October 2026',
        );
        expect(periodLabel(day('2026-10-01'), day('2026-10-31'), 'es-ES')).toBe(
            'Octubre de 2026',
        );
        expect(periodLabel(day('2026-10-01'), day('2026-12-31'), 'en-US')).toBe(
            'Q4 2026',
        );
        expect(periodLabel(day('2026-01-01'), day('2026-12-31'), 'en-US')).toBe(
            '2026',
        );
    });

    it('spells out any other range and an open end', () => {
        expect(periodLabel(day('2026-10-01'), day('2026-10-10'), 'en-US')).toBe(
            'Oct 1, 2026 – Oct 10, 2026',
        );
        expect(periodLabel(day('2026-10-01'), null, 'en-US')).toBe(
            'From Oct 1, 2026',
        );
        expect(periodLabel(null, day('2026-10-01'), 'en-US')).toBe(
            'Up to Oct 1, 2026',
        );
    });

    it('reads "All dates" without a date filter', () => {
        expect(periodLabel(null, null, 'en-US')).toBe('All dates');
    });
});

import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import {
    formatDate,
    formatDayFromDate,
    formatMonthFromYearMonth,
} from './date';

describe('formatDate', () => {
    // Reported from America/Buenos_Aires: budget periods rendered a day early
    // because a bare YYYY-MM-DD parses as UTC midnight.
    const originalTimeZone = process.env.TZ;

    beforeAll(() => {
        process.env.TZ = 'America/Argentina/Buenos_Aires';
    });

    afterAll(() => {
        process.env.TZ = originalTimeZone;
    });

    // Asserted in Swedish, the one region on the list that writes a date in ISO
    // order: the pattern names the fields now, and the region decides where
    // they go, so a bare "yyyy-MM-dd" in en-US comes back as "08/01/2026".
    it('keeps the calendar day of a bare date key at a negative UTC offset', () => {
        expect(formatDate('2026-08-01', 'yyyy-MM-dd', 'sv-SE')).toBe(
            '2026-08-01',
        );
        expect(formatDate('2026-08-31', 'yyyy-MM-dd', 'sv-SE')).toBe(
            '2026-08-31',
        );
    });

    it('still formats a full instant in the local time zone', () => {
        expect(
            formatDate('2026-08-01T12:00:00.000000Z', 'yyyy-MM-dd', 'sv-SE'),
        ).toBe('2026-08-01');
    });
});

describe('chart axis labels outside the current year', () => {
    // date-fns wrote these as "Sep '25", and the apostrophe was what said the
    // number was a year. `Intl` cannot be asked for one, so the year is written
    // out instead: "Sep 10 25" is three numbers in a row on a chart axis.
    it('writes the year out rather than leaving a bare two digits', () => {
        expect(formatMonthFromYearMonth('2025-09', 'en-US')).toBe('Sep 2025');
        expect(formatDayFromDate('2025-09-10', 'en-US')).toBe('Sep 10, 2025');
    });

    it('still drops the year inside the current one', () => {
        const thisYear = new Date().getFullYear();

        expect(formatMonthFromYearMonth(`${thisYear}-09`, 'en-US')).toBe('Sep');
        expect(formatDayFromDate(`${thisYear}-09-10`, 'en-US')).toBe('Sep 10');
    });
});

describe('formatDate across regions', () => {
    // The one reason this moved off date-fns: the pattern used to fix the order
    // as well as the fields, so every reader got the American one.
    it('orders the fields the way the region does, not the way the pattern does', () => {
        expect(formatDate('2026-09-10', 'MMM d, yyyy', 'en-US')).toBe(
            'Sep 10, 2026',
        );
        expect(formatDate('2026-09-10', 'MMM d, yyyy', 'es-ES')).toBe(
            '10 sept 2026',
        );
        expect(formatDate('2026-09-10', 'MMM d, yyyy', 'es-MX')).toBe(
            '10 sep 2026',
        );
    });

    it('splits the two regions that share a language', () => {
        // en-US is the only one of the forty-three that writes month first.
        expect(formatDate('2026-09-10', 'd/M/yyyy', 'en-US')).toBe('9/10/2026');
        expect(formatDate('2026-09-10', 'd/M/yyyy', 'en-GB')).toBe(
            '10/09/2026',
        );
    });

    it('reads a pattern for its fields and nothing else', () => {
        expect(formatDate('2026-09-10', 'yyyy', 'en-US')).toBe('2026');
        expect(formatDate('2026-09-10', 'EEEE', 'en-US')).toBe('Thursday');
        // The escaped quote in date-fns' "''yy" is not a field.
        expect(formatDate('2026-09-10', "MMM d, ''yy", 'en-US')).toBe(
            'Sep 10, 26',
        );
    });
});

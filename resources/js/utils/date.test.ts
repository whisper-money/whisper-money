import { afterAll, beforeAll, describe, expect, it } from 'vitest';
import { formatDate } from './date';

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

    it('keeps the calendar day of a bare date key at a negative UTC offset', () => {
        expect(formatDate('2026-08-01', 'yyyy-MM-dd')).toBe('2026-08-01');
        expect(formatDate('2026-08-31', 'yyyy-MM-dd')).toBe('2026-08-31');
    });

    it('still formats a full instant in the local time zone', () => {
        expect(formatDate('2026-08-01T12:00:00.000000Z', 'yyyy-MM-dd')).toBe(
            '2026-08-01',
        );
    });
});

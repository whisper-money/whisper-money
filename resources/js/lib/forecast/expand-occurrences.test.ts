import { describe, expect, it } from 'vitest';
import { addDays } from './calendar';
import { expandOccurrences } from './expand-occurrences';

describe('addDays', () => {
    it('crosses the end of a month, of a leap February and of the year', () => {
        expect(addDays('2026-12-31', 1)).toBe('2027-01-01');
        expect(addDays('2028-02-26', 7)).toBe('2028-03-04');
        expect(addDays('2027-03-04', -7)).toBe('2027-02-25');
    });
});

describe('expandOccurrences', () => {
    it('lands a weekly line every 7 days from its anchor within the range', () => {
        const occurrences = expandOccurrences(
            [
                {
                    id: 'cleaner',
                    accountId: 'current',
                    frequency: 'weekly',
                    anchorDate: '2026-12-17',
                    versions: [{ effectiveFrom: '2026-12-17', amount: -4000 }],
                },
            ],
            '2027-01-01',
            '2027-01-31',
        );

        expect(occurrences.map((occurrence) => occurrence.date)).toEqual([
            '2027-01-07',
            '2027-01-14',
            '2027-01-21',
            '2027-01-28',
        ]);
    });
});

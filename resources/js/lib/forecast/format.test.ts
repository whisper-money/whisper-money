import { describe, expect, it } from 'vitest';
import { lineOrigin } from './format';

describe('lineOrigin', () => {
    it('reads a usual transfer between the accounts as the median of 3 months', () => {
        expect(lineOrigin('transfer', 'estimate', '2026-12-01', 'en')).toBe(
            'Median of 3 months · day 1',
        );
    });

    it('reads a weekly line by its weekday, not a day of the month', () => {
        expect(
            lineOrigin('transfer', 'manual', '2026-12-03', 'en', 'weekly'),
        ).toBe('Added by you · every Thursday');
    });

    it('keeps the origin of a transfer the user added', () => {
        expect(lineOrigin('transfer', 'manual', '2026-12-05', 'en')).toBe(
            'Added by you · day 5',
        );
    });
});

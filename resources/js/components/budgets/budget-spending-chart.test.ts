import { describe, expect, it } from 'vitest';
import { getBudgetTodayMarker } from './budget-spending-chart';

const period = {
    start_date: '2026-05-20',
    end_date: '2026-06-19',
};

describe('getBudgetTodayMarker', () => {
    it('uses local date key for the first budget period', () => {
        expect(
            getBudgetTodayMarker(
                period,
                false,
                new Date('2026-05-20T00:30:00'),
            ),
        ).toBe('2026-05-20');
    });

    it('uses day index when a previous period exists', () => {
        expect(
            getBudgetTodayMarker(period, true, new Date('2026-05-22T12:00:00')),
        ).toBe(3);
    });

    it('returns null when today is outside the period', () => {
        expect(
            getBudgetTodayMarker(
                period,
                false,
                new Date('2026-05-19T23:59:59'),
            ),
        ).toBeNull();
    });
});

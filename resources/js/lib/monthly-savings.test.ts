import { MonthlySavingsMonth, SavingsGoal } from '@/types/savings-goal';
import { describe, expect, it } from 'vitest';
import {
    aggregateMonthlyGoals,
    monthStrip,
    progressPercent,
} from './monthly-savings';

function month(
    key: string,
    status: MonthlySavingsMonth['status'],
    saved = 0,
    target = 30000,
): MonthlySavingsMonth {
    return {
        month: key,
        target_type: 'amount',
        target_amount: target,
        target_rate: null,
        income_base: null,
        is_live_target: false,
        target,
        saved,
        difference: saved - target,
        status,
    };
}

function goal(history: MonthlySavingsMonth[]): SavingsGoal {
    const current = history.find((entry) => entry.status === 'in_progress');

    return {
        monthly: {
            current: current
                ? { ...current, remaining: 0, days_left: 22 }
                : null,
            history,
        },
    } as unknown as SavingsGoal;
}

describe('monthStrip', () => {
    it('pads the months before the goal existed and crosses a year boundary', () => {
        const strip = monthStrip(
            [month('2026-01', 'met'), month('2026-02', 'in_progress')],
            '2026-02',
            4,
        );

        expect(strip.map((cell) => [cell.month, cell.status])).toEqual([
            ['2025-11', 'none'],
            ['2025-12', 'none'],
            ['2026-01', 'met'],
            ['2026-02', 'in_progress'],
        ]);
    });
});

describe('aggregateMonthlyGoals', () => {
    it('adds up the current month and counts last month only for goals that had one', () => {
        const aggregate = aggregateMonthlyGoals(
            [
                goal([
                    month('2026-09', 'met'),
                    month('2026-10', 'in_progress', 12000, 30000),
                ]),
                goal([
                    month('2026-09', 'missed'),
                    month('2026-10', 'in_progress', 30000, 48000),
                ]),
                goal([month('2026-10', 'in_progress', 0, 15000)]),
            ],
            '2026-10',
        );

        expect(aggregate).toEqual({
            saved: 42000,
            target: 93000,
            daysLeft: 22,
            previousMonth: '2026-09',
            previousMet: 1,
            previousTotal: 2,
        });
    });
});

describe('progressPercent', () => {
    it('treats a zero target as complete and clamps', () => {
        expect(progressPercent(0, 0)).toBe(100);
        expect(progressPercent(45000, 30000)).toBe(100);
        expect(progressPercent(-100, 30000)).toBe(0);
        expect(progressPercent(15000, 30000)).toBe(50);
    });
});

describe('aggregateMonthlyGoals with partial months', () => {
    it('leaves partial months out of this month and last month alike', () => {
        const partialNow = {
            monthly: {
                current: {
                    ...month('2026-10', 'partial', 5000),
                    remaining: 0,
                    days_left: 3,
                },
                history: [month('2026-10', 'partial', 5000)],
            },
        } as unknown as SavingsGoal;
        const partialBefore = goal([
            month('2026-09', 'partial', 1000),
            month('2026-10', 'in_progress', 12000),
        ]);

        const aggregate = aggregateMonthlyGoals(
            [partialNow, partialBefore],
            '2026-10',
        );

        expect(aggregate.saved).toBe(12000);
        expect(aggregate.target).toBe(30000);
        expect(aggregate.previousTotal).toBe(0);
    });
});

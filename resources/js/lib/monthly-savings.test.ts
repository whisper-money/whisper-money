import { MonthlySavingsMonth, SavingsGoal } from '@/types/savings-goal';
import { describe, expect, it } from 'vitest';
import {
    aggregateMonthlyGoals,
    isTargetUnknown,
    monthStrip,
    progressPercent,
    startsLate,
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
    it('adds up the current month across running goals', () => {
        const aggregate = aggregateMonthlyGoals([
            goal([
                month('2026-09', 'met'),
                month('2026-10', 'in_progress', 12000, 30000),
            ]),
            goal([
                month('2026-09', 'missed'),
                month('2026-10', 'in_progress', 30000, 48000),
            ]),
            goal([month('2026-10', 'in_progress', 0, 15000)]),
        ]);

        expect(aggregate).toEqual({
            saved: 42000,
            target: 93000,
            allPartial: false,
            daysLeft: 22,
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
    it('leaves partial months out of this month', () => {
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

        const aggregate = aggregateMonthlyGoals([partialNow, partialBefore]);

        expect(aggregate.saved).toBe(12000);
        expect(aggregate.target).toBe(30000);
    });
});

describe('partial and unknown targets', () => {
    it('flags an aggregate where every running goal is in a partial month', () => {
        const partialOnly = {
            monthly: {
                current: {
                    ...month('2026-10', 'partial', 5000),
                    remaining: 0,
                    days_left: 3,
                },
                history: [month('2026-10', 'partial', 5000)],
            },
        } as unknown as SavingsGoal;

        expect(aggregateMonthlyGoals([partialOnly]).allPartial).toBe(true);
    });

    it('knows a target is unknown in a partial month or a live share still at 0', () => {
        expect(isTargetUnknown(month('2026-10', 'partial', 5000))).toBe(true);
        expect(
            isTargetUnknown({
                ...month('2026-10', 'in_progress', 0, 0),
                is_live_target: true,
            }),
        ).toBe(true);
        expect(
            isTargetUnknown(month('2026-10', 'in_progress', 15000, 30000)),
        ).toBe(false);
    });

    it('knows when a goal created today starts with a partial month', () => {
        expect(startsLate(new Date(2026, 9, 26))).toBe(false);
        expect(startsLate(new Date(2026, 9, 27))).toBe(true);
        expect(startsLate(new Date(2026, 8, 26))).toBe(true);
        expect(startsLate(new Date(2026, 1, 23))).toBe(false);
        expect(startsLate(new Date(2026, 1, 24))).toBe(true);
    });
});

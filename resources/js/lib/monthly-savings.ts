import {
    MonthlySavingsMonth,
    MonthlySavingsStatus,
    SavingsGoal,
} from '@/types/savings-goal';

/** A cell of the 12-month strip; `none` is a month before the goal existed. */
export type MonthStripStatus = MonthlySavingsStatus | 'none';

export interface MonthStripCell {
    /** YYYY-MM */
    month: string;
    status: MonthStripStatus;
    entry: MonthlySavingsMonth | null;
}

export function monthKey(date: Date): string {
    return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`;
}

/** First day of a YYYY-MM month, in local time. */
export function monthDate(key: string): Date {
    const [year, month] = key.split('-').map(Number);

    return new Date(year, month - 1, 1);
}

/**
 * The last `count` calendar months ending with `endMonth`, each matched to the
 * goal's history. Months the goal did not exist in yet read as `none`.
 */
export function monthStrip(
    history: MonthlySavingsMonth[],
    endMonth: string,
    count = 12,
): MonthStripCell[] {
    const byMonth = new Map(history.map((entry) => [entry.month, entry]));
    const end = monthDate(endMonth);

    return Array.from({ length: count }, (_, index) => {
        const month = monthKey(
            new Date(
                end.getFullYear(),
                end.getMonth() - (count - 1 - index),
                1,
            ),
        );
        const entry = byMonth.get(month) ?? null;

        return { month, status: entry?.status ?? 'none', entry };
    });
}

export interface MonthlySavingsAggregate {
    saved: number;
    target: number;
    daysLeft: number | null;
    /** How the goals did in the month before the current one. */
    previousMonth: string;
    previousMet: number;
    previousTotal: number;
}

/**
 * The current month across every running monthly goal, and how many of them
 * were met the month before. A goal created this month has no previous month
 * and is left out of that count.
 */
export function aggregateMonthlyGoals(
    goals: SavingsGoal[],
    currentMonth: string,
): MonthlySavingsAggregate {
    const current = monthDate(currentMonth);
    const previousMonth = monthKey(
        new Date(current.getFullYear(), current.getMonth() - 1, 1),
    );
    const previous = goals
        .map((goal) =>
            goal.monthly?.history.find(
                (entry) => entry.month === previousMonth,
            ),
        )
        .filter((entry) => entry !== undefined);

    return {
        saved: sum(goals.map((goal) => goal.monthly?.current?.saved ?? 0)),
        target: sum(goals.map((goal) => goal.monthly?.current?.target ?? 0)),
        daysLeft:
            goals.find((goal) => goal.monthly?.current)?.monthly?.current
                ?.days_left ?? null,
        previousMonth,
        previousMet: previous.filter((entry) => entry.status === 'met').length,
        previousTotal: previous.length,
    };
}

/** Progress towards a target, clamped to 0-100. A zero target is complete. */
export function progressPercent(saved: number, target: number): number {
    if (target <= 0) {
        return 100;
    }

    return Math.min(100, Math.max(0, (saved / target) * 100));
}

function sum(values: number[]): number {
    return values.reduce((total, value) => total + value, 0);
}

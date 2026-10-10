import {
    MonthlySavingsMonth,
    MonthlySavingsStatus,
    SavingsGoal,
} from '@/types/savings-goal';
import { __ } from '@/utils/i18n';

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
    /**
     * Every goal with a month in progress is in a partial one: there is no
     * target to add up yet, so the aggregate says so instead of "0 of 0".
     */
    allPartial: boolean;
    daysLeft: number | null;
    /** How the goals did in the month before the current one. */
    previousMonth: string;
    previousMet: number;
    previousTotal: number;
}

/**
 * The current month across every running monthly goal, and how many of them
 * were met the month before. A goal created this month has no previous month
 * and is left out of that count, and so is a partial month: it has no target
 * and no verdict to add up.
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
        .filter(isAddedUp);
    const running = goals
        .map((goal) => goal.monthly?.current)
        .filter(isAddedUp);

    return {
        saved: sum(running.map((entry) => entry.saved)),
        target: sum(running.map((entry) => entry.target)),
        allPartial:
            running.length === 0 &&
            goals.some((goal) => goal.monthly?.current?.status === 'partial'),
        daysLeft:
            goals.find((goal) => goal.monthly?.current)?.monthly?.current
                ?.days_left ?? null,
        previousMonth,
        previousMet: previous.filter((entry) => entry.status === 'met').length,
        previousTotal: previous.length,
    };
}

/**
 * The month the server considers current for these goals, so the strip and the
 * "previous month" count follow the stats rather than the browser's clock.
 * Falls back to the browser only when no goal has a month in progress.
 */
export function currentMonthOf(goals: SavingsGoal[]): string {
    return (
        goals.find((goal) => goal.monthly?.current)?.monthly?.current?.month ??
        monthKey(new Date())
    );
}

/**
 * Mirrors SavingsGoal::LATE_START_DAYS: a goal created in the last this-many
 * days of a month gets that month as a partial one.
 */
const LATE_START_DAYS = 5;

/** Whether a goal created on `date` would start with a partial month. */
export function startsLate(date: Date): boolean {
    const lastDay = new Date(
        date.getFullYear(),
        date.getMonth() + 1,
        0,
    ).getDate();

    return date.getDate() > lastDay - LATE_START_DAYS;
}

export function daysLeftLabel(count: number): string {
    return count === 1 ? __('1 day left') : __(':count days left', { count });
}

/** A month's percentage-of-income rate as written in copy: 20, 12.5. */
export function formatRate(rate: number | null): string {
    return String(Number(rate ?? 0));
}

/** Progress towards a target, clamped to 0-100. A zero target is complete. */
export function progressPercent(saved: number, target: number): number {
    if (target <= 0) {
        return 100;
    }

    return Math.min(100, Math.max(0, (saved / target) * 100));
}

/**
 * The bar of a month in progress. A partial month has no target, and a share
 * of an income that has not arrived yet is a target of 0 that is not met:
 * neither draws a full bar.
 */
export function monthProgressPercent(month: MonthlySavingsMonth): number {
    if (isTargetUnknown(month)) {
        return 0;
    }

    return progressPercent(month.saved, month.target);
}

/** A month whose target is not known yet: partial, or a live share still at 0. */
export function isTargetUnknown(month: MonthlySavingsMonth): boolean {
    return (
        month.status === 'partial' ||
        (month.is_live_target && month.target <= 0)
    );
}

function isAddedUp<T extends MonthlySavingsMonth>(
    entry: T | null | undefined,
): entry is T {
    return entry != null && entry.status !== 'partial';
}

function sum(values: number[]): number {
    return values.reduce((total, value) => total + value, 0);
}

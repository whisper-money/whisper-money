import { __ } from '@/utils/i18n';
import { Label } from './label';
import { Transaction } from './transaction';
import { UUID } from './uuid';

export type SavingsGoalStatus = 'ahead' | 'on_track' | 'behind' | 'completed';

export interface SavingsGoalStats {
    saved: number;
    target: number;
    percentage: number;
    target_date: string | null;
    rate_per_day: number;
    expected_today: number | null;
    status: SavingsGoalStatus | null;
    estimated_date: string | null;
    required_per_month: number | null;
}

export type SavingsGoalKind = 'one_off' | 'monthly';

export type MonthlyTargetType = 'amount' | 'income_rate';

/**
 * Mirrors App\Enums\SavingsGoalMonthStatus. A `partial` month (the goal started
 * in its last days) and an `archived` one (the goal was archived during it)
 * show what was saved but get no verdict.
 */
export type MonthlySavingsStatus =
    | 'met'
    | 'missed'
    | 'in_progress'
    | 'partial'
    | 'archived';

/** One calendar month of a monthly goal, judged against its own target. */
export interface MonthlySavingsMonth {
    /** YYYY-MM */
    month: string;
    target_type: MonthlyTargetType;
    target_amount: number | null;
    target_rate: number | null;
    /** The income a share-of-income target was worked out from. */
    income_base: number | null;
    /** True while the target follows this month's income instead of a frozen base. */
    is_live_target: boolean;
    target: number;
    saved: number;
    difference: number;
    status: MonthlySavingsStatus;
}

/**
 * What a page needs to add monthly goals up for the month in progress. A
 * full goal fits it, and so does the dashboard's slimmer row.
 */
export interface MonthlyGoalSnapshot {
    monthly?: Pick<MonthlySavingsStats, 'current'>;
}

/** How the goals did in one month: met of judged, archived goals included. */
export interface MonthlyVerdicts {
    /** YYYY-MM */
    month: string;
    met: number;
    total: number;
}

/** A running goal as the dashboard card gets it: this month only. */
export interface DashboardMonthlyGoal extends MonthlyGoalSnapshot {
    id: UUID;
    name: string;
}

/** A savings account the auto-tag option can point at. */
export interface AutoTagAccount {
    id: UUID;
    name: string;
    bank: { name: string } | null;
    /** The running monthly goal this account already feeds, if any. */
    used_by: string | null;
}

export interface MonthlySavingsCurrent extends MonthlySavingsMonth {
    remaining: number;
    days_left: number;
}

export interface MonthlySavingsStats {
    current: MonthlySavingsCurrent | null;
    /** Every month since the goal was created, oldest first. */
    history: MonthlySavingsMonth[];
    months_met: number;
    months_closed: number;
    cumulative_difference: number;
    cumulative_saved: number;
    cumulative_target: number;
    streak: number;
    best_streak: number;
}

export interface SavingsGoal {
    id: UUID;
    user_id: UUID;
    label_id: UUID | null;
    name: string;
    kind: SavingsGoalKind;
    monthly_target_type: MonthlyTargetType | null;
    monthly_target_amount: number | null;
    monthly_target_rate: number | null;
    notify_on_month_end_reminder: boolean;
    /** The savings account a monthly goal's auto-tag rule watches. */
    auto_tag_account_id: UUID | null;
    target_amount: number;
    initial_amount: number;
    target_date: string | null;
    /** Manual order on the Planning list; null until the user drags something. */
    position: number | null;
    /** Set once and never cleared: archiving a goal cannot be undone. */
    archived_at: string | null;
    /** The saved amount frozen at archive time; null while the goal is running. */
    archived_saved_amount: number | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    label?: Label;
    stats?: SavingsGoalStats;
    /** Only on monthly goals. */
    monthly?: MonthlySavingsStats;
    transactions?: Transaction[];
}

export function getSavingsGoalStatusLabel(status: SavingsGoalStatus): string {
    const labels: Record<SavingsGoalStatus, string> = {
        ahead: __('Ahead of schedule'),
        on_track: __('On track'),
        behind: __('Behind schedule'),
        completed: __('Goal reached'),
    };
    return labels[status];
}

export function getSavingsGoalStatusColor(status: SavingsGoalStatus): string {
    const colors: Record<SavingsGoalStatus, string> = {
        ahead: 'text-green-600 dark:text-green-400',
        on_track: 'text-green-600 dark:text-green-400',
        behind: 'text-yellow-600 dark:text-yellow-400',
        completed: 'text-green-600 dark:text-green-400',
    };
    return colors[status];
}

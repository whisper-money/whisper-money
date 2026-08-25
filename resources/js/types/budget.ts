import { __ } from '@/utils/i18n';
import { Category } from './category';
import { Label } from './label';
import { Transaction } from './transaction';
import { UUID } from './uuid';

export const BUDGET_PERIOD_TYPES = [
    'monthly',
    'weekly',
    'biweekly',
    'yearly',
] as const;

export type BudgetPeriodType = (typeof BUDGET_PERIOD_TYPES)[number];

export const ROLLOVER_TYPES = ['carry_over', 'reset'] as const;

export type RolloverType = (typeof ROLLOVER_TYPES)[number];

export interface Budget {
    id: UUID;
    user_id: UUID;
    name: string;
    period_type: BudgetPeriodType;
    period_start_day: number | null;
    rollover_type: RolloverType;
    is_catch_all: boolean;
    notify_on_new_transaction: boolean;
    notify_on_close_to_limit: boolean;
    notify_on_over_limit: boolean;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    categories?: Category[];
    labels?: Label[];
    periods?: BudgetPeriod[];
}

export interface BudgetCategory {
    id: UUID;
    budget_id: UUID;
    category_id: UUID;
    updated_at: string;
}

export interface BudgetLabel {
    id: UUID;
    budget_id: UUID;
    label_id: UUID;
    updated_at: string;
}

export interface BudgetPeriod {
    id: UUID;
    budget_id: UUID;
    start_date: string;
    end_date: string;
    allocated_amount: number;
    carried_over_amount: number;
    processing_historical: boolean;
    created_at: string;
    updated_at: string;
    budget_transactions?: BudgetTransaction[];
}

export interface BudgetTransaction {
    id: UUID;
    transaction_id: UUID;
    budget_period_id: UUID;
    amount: number;
    created_at: string;
    updated_at: string;
    transaction?: Transaction;
}

export interface BudgetHistoryData {
    period_start: string;
    period_end: string;
    budgeted: number;
    spent: number;
}

export function getBudgetPeriodTypeLabel(type: BudgetPeriodType): string {
    const labels: Record<BudgetPeriodType, string> = {
        monthly: __('Monthly'),
        weekly: __('Weekly'),
        biweekly: __('Bi-weekly'),
        yearly: __('Yearly'),
    };
    return labels[type];
}

export function getRolloverTypeLabel(type: RolloverType): string {
    const labels: Record<RolloverType, string> = {
        carry_over: __('Carry Over'),
        reset: __('Reset/Pool'),
    };
    return labels[type];
}

export function getRolloverTypeDescription(type: RolloverType): string {
    const descriptions: Record<RolloverType, string> = {
        carry_over: __('Remaining balance carries over to next period'),
        reset: __('Remaining balance returns to available money pool'),
    };
    return descriptions[type];
}

/**
 * How much of the current period's allocation is already spent, as a
 * percentage. The card and the Planning list's ordering both read it, so it
 * has to be computed in one place.
 */
export function budgetPercentageUsed(budget: Budget): number {
    const period = budget.periods?.[0];

    if (!period || period.allocated_amount <= 0) {
        return 0;
    }

    const spent =
        period.budget_transactions?.reduce((sum, t) => sum + t.amount, 0) ?? 0;

    return (spent / period.allocated_amount) * 100;
}

export type BudgetSeverity = 'over' | 'near' | 'ok';

/**
 * Where a budget sits against its allocation. The card's colour and the
 * Planning list's ordering both read it, so the position an item takes in the
 * list can never disagree with the colour the user is looking at.
 */
export function budgetSeverity(budget: Budget): BudgetSeverity {
    const used = budgetPercentageUsed(budget);

    if (used >= 100) {
        return 'over';
    }

    return used >= 80 ? 'near' : 'ok';
}

export function getBudgetSeverityColor(severity: BudgetSeverity): string {
    const colors: Record<BudgetSeverity, string> = {
        over: 'text-red-600 dark:text-red-400',
        near: 'text-yellow-600 dark:text-yellow-400',
        ok: 'text-green-600 dark:text-green-400',
    };

    return colors[severity];
}

import { Category } from './category';
import { Label } from './label';
import { Transaction } from './transaction';
import { UUID } from './uuid';

export const BUDGET_PERIOD_TYPES = [
    'monthly',
    'weekly',
    'biweekly',
    'custom',
] as const;

export type BudgetPeriodType = (typeof BUDGET_PERIOD_TYPES)[number];

export const ROLLOVER_TYPES = ['carry_over', 'reset'] as const;

export type RolloverType = (typeof ROLLOVER_TYPES)[number];

export interface Budget {
    id: UUID;
    user_id: UUID;
    name: string;
    period_type: BudgetPeriodType;
    period_duration: number | null;
    period_start_day: number | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    budget_categories?: BudgetCategory[];
    periods?: BudgetPeriod[];
}

export interface BudgetCategory {
    id: UUID;
    budget_id: UUID;
    category_id: UUID;
    rollover_type: RolloverType;
    created_at: string;
    updated_at: string;
    category?: Category;
    labels?: Label[];
    allocations?: BudgetPeriodAllocation[];
}

export interface BudgetPeriod {
    id: UUID;
    budget_id: UUID;
    start_date: string;
    end_date: string;
    carried_over_amount: number;
    created_at: string;
    updated_at: string;
    allocations?: BudgetPeriodAllocation[];
}

export interface BudgetPeriodAllocation {
    id: UUID;
    budget_period_id: UUID;
    budget_category_id: UUID;
    allocated_amount: number;
    created_at: string;
    updated_at: string;
    budget_category?: BudgetCategory;
    budget_transactions?: BudgetTransaction[];
    spent?: number;
    remaining?: number;
}

export interface BudgetTransaction {
    id: UUID;
    transaction_id: UUID;
    budget_period_allocation_id: UUID;
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
        monthly: 'Monthly',
        weekly: 'Weekly',
        biweekly: 'Bi-weekly',
        custom: 'Custom',
    };
    return labels[type];
}

export function getRolloverTypeLabel(type: RolloverType): string {
    const labels: Record<RolloverType, string> = {
        carry_over: 'Carry Over',
        reset: 'Reset/Pool',
    };
    return labels[type];
}

export function getRolloverTypeDescription(type: RolloverType): string {
    const descriptions: Record<RolloverType, string> = {
        carry_over: 'Remaining balance carries over to next period',
        reset: 'Remaining balance returns to available money pool',
    };
    return descriptions[type];
}


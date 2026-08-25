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

export interface SavingsGoal {
    id: UUID;
    user_id: UUID;
    label_id: UUID | null;
    name: string;
    target_amount: number;
    initial_amount: number;
    target_date: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    label?: Label;
    stats?: SavingsGoalStats;
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

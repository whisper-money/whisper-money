import { Category } from './category';
import { Label } from './label';
import { UUID } from './uuid';

export const SUBSCRIPTIONS_PERIOD_TYPES = [
    'monthly',
    'weekly',
    'biweekly',
    'yearly',
] as const;

export type SubscriptionPeriodType = (typeof SUBSCRIPTIONS_PERIOD_TYPES)[number];

export interface PersonalSubscription {
    id: UUID;
    user_id: UUID;
    name: string;
    amount: number;
    currency: string;
    billing_cycle: SubscriptionPeriodType;
    next_billing_date: string;
    color: string | null;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
    categories?: Category[];
    labels?: Label[];
    periods?: PersonalSubscriptionPeriod[];
}

/* export interface PersonalSubscriptionPeriod {
    id: UUID;
    budget_id: UUID;
    start_date: string;
    end_date: string;
    allocated_amount: number;
    carried_over_amount: number;
    processing_historical: boolean;
    created_at: string;
    updated_at: string;
} */
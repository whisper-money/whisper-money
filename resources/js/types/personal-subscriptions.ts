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
}
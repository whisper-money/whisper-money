import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { useMemo } from 'react';

/**
 * Cheapest monthly equivalent across all plans, used wherever the onboarding
 * has to quote a "from X/month" price. Yearly plans are divided by 12 so the
 * comparison is like-for-like.
 */
export function useCheapestMonthlyPrice(): number | null {
    const { pricing } = usePage<SharedData>().props;

    return useMemo(() => {
        const plans = Object.values(pricing.plans);

        if (plans.length === 0) {
            return null;
        }

        return Math.min(
            ...plans.map((plan) =>
                plan.billing_period === 'year' ? plan.price / 12 : plan.price,
            ),
        );
    }, [pricing.plans]);
}

import { summary as summaryRoute } from '@/routes/onboarding';
import axios from 'axios';
import { useEffect, useState } from 'react';

/**
 * What the user has to show for the flow, read from their own data rather than
 * from what the wizard remembers doing. The last two steps are both written
 * from it: one builds a target on real spending, the other reports what exists.
 */
export interface OnboardingSummary {
    currency_code: string;
    accounts: number;
    connected_accounts: number;
    transactions: number;
    /** Months of history the ledger spans, inclusive of both ends. */
    months: number;
    /** Automation rules, which are what files everything arriving from now on. */
    rules: number;
    /** Minor units, positive. Null for someone with no spending to read. */
    monthly_spending: number | null;
    /** Merchants that billed the same amount three months running. */
    recurring_count: number;
    /** Minor units, positive: what those repeat charges come to in a month. */
    recurring_amount: number;
    /** Minor units. The monthly target, once the user has actually set one. */
    target: number | null;
}

/**
 * `undefined` while it loads, `null` when it could not be read at all — the
 * last screen of the onboarding still has to let the user out, so the failure
 * is a value the caller handles rather than a spinner nobody can leave.
 */
export function useOnboardingSummary(): OnboardingSummary | null | undefined {
    const [summary, setSummary] = useState<
        OnboardingSummary | null | undefined
    >(undefined);

    useEffect(() => {
        let cancelled = false;

        axios
            .get<OnboardingSummary>(summaryRoute().url)
            .then(({ data }) => {
                if (!cancelled) {
                    setSummary(data);
                }
            })
            .catch(() => {
                if (!cancelled) {
                    setSummary(null);
                }
            });

        return () => {
            cancelled = true;
        };
    }, []);

    return summary;
}

import { type NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { type DashboardProps } from '@/types/dashboard';

/** What the net worth widgets draw before the deferred prop arrives. */
export const EMPTY_NET_WORTH_EVOLUTION: NetWorthEvolutionData = {
    data: [],
    accounts: {},
    currency_code: 'USD',
};

/** The deferred net worth evolution, or the empty one while it loads. */
export function netWorthEvolutionOf(
    data: DashboardProps,
): NetWorthEvolutionData {
    return data.netWorthEvolution ?? EMPTY_NET_WORTH_EVOLUTION;
}

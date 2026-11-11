import { type MonthlySummaryNoticeData } from '@/components/dashboard/monthly-summary-notice';
import { type LabelSpending } from '@/components/dashboard/top-labels-card';
import { type NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { type SharedData } from '@/types';
import { type CreditCardUsage } from '@/types/account';
import { type Category } from '@/types/category';
import {
    type DashboardMonthlyGoal,
    type MonthlyVerdicts,
} from '@/types/savings-goal';

export interface CashflowSummary {
    income: number;
    expense: number;
    net: number;
    savings_rate: number;
}

/** The dashboard page props: most of them deferred, all read from the server. */
export interface DashboardProps extends SharedData {
    netWorthEvolution?: NetWorthEvolutionData;
    topCategories?: Array<{
        category: Category | null;
        category_id?: string | null;
        amount: number;
        previous_amount: number;
        total_amount: number;
        has_children?: boolean;
        is_direct?: boolean;
    }>;
    topLabels?: LabelSpending[];
    cashflowSummary?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    };
    openIntegrationRequests?: boolean;
    /** Deferred: what is in use of each credit card, by account id. */
    creditCardUsage?: Record<string, CreditCardUsage>;
    monthlySummary?: MonthlySummaryNoticeData | null;
    monthlySavingsGoals?: DashboardMonthlyGoal[];
    monthlySavingsLastMonth?: MonthlyVerdicts | null;
}

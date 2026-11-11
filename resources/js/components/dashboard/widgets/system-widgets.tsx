import { CashflowSummaryCard } from '@/components/dashboard/cashflow-summary-card';
import { MonthlySavingsDashboardCard } from '@/components/dashboard/monthly-savings-dashboard-card';
import { NetWorthChart } from '@/components/dashboard/net-worth-chart';
import { TopCategoriesCard } from '@/components/dashboard/top-categories-card';
import { TopLabelsCard } from '@/components/dashboard/top-labels-card';
import { PrivacyRevealScope } from '@/contexts/privacy-mode-context';
import { Deferred } from '@inertiajs/react';
import {
    EMPTY_NET_WORTH_EVOLUTION,
    netWorthEvolutionOf,
} from './net-worth-evolution';
import { type SystemWidgetProps } from './types';

export function NetWorthWidget({ data }: SystemWidgetProps) {
    return (
        <Deferred
            data="netWorthEvolution"
            fallback={
                <NetWorthChart
                    data={EMPTY_NET_WORTH_EVOLUTION}
                    loading={true}
                />
            }
        >
            <PrivacyRevealScope>
                <NetWorthChart data={netWorthEvolutionOf(data)} />
            </PrivacyRevealScope>
        </Deferred>
    );
}

export function MonthlySavingsWidget({ data }: SystemWidgetProps) {
    // Nothing drawn while it loads, for the same reason as the labels widget:
    // most users have no monthly goal.
    return (
        <Deferred data="monthlySavingsGoals" fallback={<></>}>
            <MonthlySavingsDashboardCard
                goals={data.monthlySavingsGoals ?? []}
                lastMonth={data.monthlySavingsLastMonth}
                currencyCode={data.auth.user.currency_code}
            />
        </Deferred>
    );
}

export function TopCategoriesWidget({ data }: SystemWidgetProps) {
    return (
        <Deferred
            data="topCategories"
            fallback={<TopCategoriesCard categories={[]} loading={true} />}
        >
            <PrivacyRevealScope>
                <TopCategoriesCard categories={data.topCategories ?? []} />
            </PrivacyRevealScope>
        </Deferred>
    );
}

export function TopLabelsWidget({ data }: SystemWidgetProps) {
    // An empty fragment, not null: Deferred rejects a falsy fallback. Nothing
    // is drawn on purpose — most users have nothing labelled, and a
    // placeholder for a card that then disappears reads as a glitch.
    return (
        <Deferred data="topLabels" fallback={<></>}>
            <PrivacyRevealScope>
                <TopLabelsCard labels={data.topLabels ?? []} />
            </PrivacyRevealScope>
        </Deferred>
    );
}

export function CashflowWidget({ data }: SystemWidgetProps) {
    if (!data.features.cashflow) {
        return null;
    }

    return (
        <Deferred
            data="cashflowSummary"
            fallback={<CashflowSummaryCard loading={true} />}
        >
            <PrivacyRevealScope>
                <CashflowSummaryCard data={data.cashflowSummary ?? null} />
            </PrivacyRevealScope>
        </Deferred>
    );
}

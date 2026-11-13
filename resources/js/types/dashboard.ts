import { type MonthlySummaryNoticeData } from '@/components/dashboard/monthly-summary-notice';
import { type LabelSpending } from '@/components/dashboard/top-labels-card';
import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
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

/** One of the user's own dashboards, as the picker lists it. */
export interface DashboardSummary {
    id: string;
    name: string;
    position: number;
}

/** A widget placed on a user dashboard. */
export interface DashboardWidgetData {
    id: string;
    /** A system widget registry id, or null for a user-written widget. */
    system_widget: string | null;
    widget_definition_id: string | null;
    position: number;
    width: WidgetWidth;
    height: WidgetHeight;
    config: Record<string, unknown> | null;
}

/** The sizes a user widget's manifest admits, as the agent saved them. */
export interface UserWidgetManifestSize {
    width: WidgetWidth;
    height: WidgetHeight;
    allowed_widths?: WidgetWidth[];
    allowed_heights?: WidgetHeight[];
}

/**
 * What the dashboard draws around a user widget: its card (the default), or
 * nothing, for a row of tiles that draw their own cards.
 */
export type WidgetFrame = 'card' | 'none';

/** A widget the user's agent wrote, as edit mode lists it to be placed. */
export interface WidgetDefinitionSummary {
    id: string;
    name: string;
    size: UserWidgetManifestSize;
}

/** The runtime error a user widget raised, as it was stored with it. */
export interface UserWidgetError {
    revision: number;
    phase: 'load' | 'render';
    message: string;
    stack: string | null;
    component_stack: string | null;
}

export interface UserWidgetRevision {
    number: number;
    created_via: string;
    /** The revision this one restored, when it was a "go back". */
    restored_from: number | null;
    created_at: string | null;
}

/** A user widget placed on the dashboard on screen, ready to run. */
export interface UserWidgetDefinition {
    id: string;
    name: string;
    revision: number;
    /** The compiled module. */
    js: string;
    manifest: {
        size: UserWidgetManifestSize;
        frame?: WidgetFrame;
    } & Record<string, unknown>;
    last_error: UserWidgetError | null;
    last_error_at: string | null;
    /** Newest first. */
    revisions: UserWidgetRevision[];
}

/** The user dashboard on screen, with its widgets in order. */
export interface SelectedDashboard {
    id: string;
    name: string;
    widgets: DashboardWidgetData[];
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
    /** Deferred: the user's own dashboards, in their order. */
    dashboards?: DashboardSummary[];
    /** The dashboard the app opens on; null is the system dashboard. */
    defaultDashboardId: string | null;
    /** The user dashboard on screen; null is the system dashboard. */
    selectedDashboard: SelectedDashboard | null;
    /** Deferred: the user widgets on the dashboard on screen, by id. */
    userWidgets?: Record<string, UserWidgetDefinition>;
    /** Deferred: every widget the user's agent wrote, to place one. */
    widgetDefinitions?: WidgetDefinitionSummary[];
}

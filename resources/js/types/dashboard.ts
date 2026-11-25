import { type MonthlySummaryNoticeData } from '@/components/dashboard/monthly-summary-notice';
import { type TopCategoriesData } from '@/components/dashboard/top-categories-card';
import { type LabelSpending } from '@/components/dashboard/top-labels-card';
import {
    type WidgetHeight,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { type NetWorthEvolutionData } from '@/hooks/use-dashboard-data';
import { type SharedData } from '@/types';
import {
    type BalanceDialogAccount,
    type CreditCardUsage,
} from '@/types/account';
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

/** A step of the dashboard's "Finish setting up" card, in its own shape. */
export type SetupChecklistStep =
    | {
          key: 'imported';
          done: true;
          count: number;
          /** Y-m-d of the oldest and newest movement. */
          first_date: string;
          last_date: string;
          accounts_count: number;
          /** Set when the movements are all in one account. */
          account_name: string | null;
      }
    | {
          key: 'spending_limit';
          done: boolean;
          /** This month's limit, in minor units. */
          limit: number | null;
          /** How far under the month it was built on the user set it. */
          under_last_month: number | null;
          currency_code: string;
      }
    | { key: 'categorize'; done: boolean; count: number }
    | {
          key: 'balance';
          done: false;
          /** The first account that never got a balance. */
          account: BalanceDialogAccount;
          /** How many more accounts are like it. */
          others_count: number;
      };

export interface SetupChecklist {
    steps: SetupChecklistStep[];
}

/** What every first-dashboard state says in the page header. */
interface FirstDashboardHeader {
    /** Y-m-d, today on the user's calendar. */
    today: string;
    /** Y-m-d, the day the onboarding ended on the user's calendar. */
    tracking_since: string;
    accounts_count: number;
    /** The monthly spending the user guessed in the onboarding, in minor units. */
    spending_guess: number | null;
    /** The user's currency, the one the guess is in. */
    currency_code: string;
}

/** One round figure typed in during the onboarding, by category. */
export interface EstimateRow {
    name: string;
    /** Positive, in minor units. */
    amount: number;
}

/** The onboarding estimates of the month they stand for. */
export interface DashboardEstimates {
    /** Y-m. */
    month: string;
    count: number;
    total: number;
    currency_code: string;
    /** The account the emailed import link opens on; null when it cannot take a file. */
    account_id: string | null;
    /** Largest first. */
    rows: EstimateRow[];
}

/**
 * The system dashboard of a user with no real movement yet: only the
 * onboarding estimates, or only balances and no current account.
 */
export type FirstDashboardState =
    | ({
          state: 'estimates';
          estimates: DashboardEstimates;
      } & FirstDashboardHeader)
    | ({
          state: 'balances';
          /** Whether an account can take a bank file. */
          can_import: boolean;
      } & FirstDashboardHeader);

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

/**
 * The monthly spending limit the onboarding target created: the catch-all
 * budget's current period, what it allows and what it has counted so far.
 */
export interface SpendingLimit {
    budget_id: string;
    allocated: number;
    spent: number;
    /** Whole days left in the period after today. */
    days_left: number;
}

/** The dashboard page props: most of them deferred, all read from the server. */
export interface DashboardProps extends SharedData {
    netWorthEvolution?: NetWorthEvolutionData;
    topCategories?: TopCategoriesData;
    topLabels?: LabelSpending[];
    cashflowSummary?: {
        current: CashflowSummary;
        previous: CashflowSummary;
    };
    /** Deferred: the catch-all budget's period in progress, if any. */
    spendingLimit?: SpendingLimit | null;
    openIntegrationRequests?: boolean;
    /** Deferred: what is in use of each credit card, by account id. */
    creditCardUsage?: Record<string, CreditCardUsage>;
    monthlySummary?: MonthlySummaryNoticeData | null;
    /** Deferred: the "Finish setting up" card, null when there is none to draw. */
    setupChecklist?: SetupChecklist | null;
    /** Deferred: the estimate-only or balance-only state, null for everybody else. */
    firstDashboardState?: FirstDashboardState | null;
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

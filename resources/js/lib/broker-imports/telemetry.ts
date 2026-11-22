import {
    type BrokerAccountRole,
    type BrokerFileKind,
} from '@/lib/broker-imports/types';
import {
    fileExtension,
    shouldReportImportError,
    trackImport,
} from '@/lib/full-import-telemetry';
import { isLeavingPage } from '@/lib/leave-page';
import { type ImportOutcome } from '@/lib/transaction-import';
import * as Sentry from '@sentry/react';

/**
 * What a broker import tells PostHog and Sentry. Like the full import's,
 * only counts, enums and booleans: never a cell of the file, its name, an
 * account's name or any amount (no balance, no invested sum, no value).
 */

/** Where a broker import that threw was: reading the file, making an account, or the rest. */
export type BrokerImportStage = 'read' | 'accounts' | 'run';

/** What every run event carries, whether it finished or threw. */
export interface BrokerRunContext {
    rows: number;
    accountsCreated: Partial<Record<BrokerAccountRole, number>>;
    /** A remembered link sent the file to the accounts of last time. */
    reimport: boolean;
}

/** What a finished run says, before PostHog hears of it. */
export interface BrokerRunSummary extends BrokerRunContext {
    outcome: ImportOutcome;
    balancesFailed: boolean;
    balancesSent: number;
    /** Whether the plan has a portfolio and the user kept it. */
    portfolio: 'imported' | 'skipped' | 'n/a';
    cashConnected: boolean;
}

/** The stage an error was thrown at, when the code that threw said so. */
const stages = new WeakMap<object, BrokerImportStage>();

/** Marks an error as thrown at `stage`, for {@link brokerErrorStage}. */
export function atBrokerStage<T>(error: T, stage: BrokerImportStage): T {
    if (typeof error === 'object' && error !== null) {
        stages.set(error, stage);
    }

    return error;
}

/** The stage an error was marked with, else `fallback`. */
export function brokerErrorStage(
    error: unknown,
    fallback: BrokerImportStage,
): BrokerImportStage {
    return (
        (typeof error === 'object' && error !== null && stages.get(error)) ||
        fallback
    );
}

/** The HTTP status a failed request carries, 0 when it never got an answer. */
function responseStatus(error: unknown): number {
    const status = (error as { response?: { status?: unknown } } | null)
        ?.response?.status;

    return typeof status === 'number' ? status : 0;
}

/** A run's context, flattened into event properties. */
function contextProperties(context: BrokerRunContext): Record<string, unknown> {
    return {
        rows: context.rows,
        accounts_created_cash: context.accountsCreated.cash ?? 0,
        accounts_created_portfolio: context.accountsCreated.portfolio ?? 0,
        reimport: context.reimport,
    };
}

/** An error to Sentry, tagged as the broker import's, with its HTTP status. */
function captureBrokerError(
    error: unknown,
    tags: { kind: BrokerFileKind; stage: string; role?: BrokerAccountRole },
    extra: Record<string, unknown> = {},
): void {
    Sentry.captureException(error, {
        tags: { feature: 'broker_import', ...tags },
        extra: { status: responseStatus(error), ...extra },
    });
}

/** A broker file was recognised and read. */
export function trackBrokerFileDetected(
    kind: BrokerFileKind,
    fileName: string,
    reimport: boolean,
): void {
    trackImport('broker_import_file_detected', {
        kind,
        extension: fileExtension(fileName),
        reimport,
    });
}

/** `broker_import_completed`, with how the run went. */
export function trackBrokerImportCompleted(
    kind: BrokerFileKind,
    summary: BrokerRunSummary,
): void {
    trackImport('broker_import_completed', {
        kind,
        ...contextProperties(summary),
        success_count: summary.outcome.successCount,
        failure_count: summary.outcome.errors.length,
        uncategorized_count: summary.outcome.uncategorizedCount,
        portfolio: summary.portfolio,
        cash_connected: summary.cashConnected,
        balances_failed: summary.balancesFailed,
        balances_sent: summary.balancesSent,
    });
}

/**
 * `broker_import_failed` for an import that threw, and the error to Sentry
 * when somebody should look at it: a refusal (4xx) the user is told about
 * is not. Without a run context it failed reading the file.
 */
export function reportBrokerImportFailed(
    error: unknown,
    kind: BrokerFileKind,
    stage: BrokerImportStage,
    context?: BrokerRunContext,
): void {
    const extra = context ? contextProperties(context) : {};

    trackImport('broker_import_failed', {
        kind,
        stage,
        status: responseStatus(error),
        ...extra,
    });

    if (shouldReportImportError(error)) {
        captureBrokerError(error, { kind, stage }, extra);
    }
}

/**
 * A daily balance series the server did not take. Reported even when it was
 * refused (4xx): the import planned it, so a refusal is a bug of ours, and
 * the user is only told that some balances are missing.
 */
export function reportBrokerBalancesFailed(
    error: unknown,
    kind: BrokerFileKind,
    details: {
        role: BrokerAccountRole;
        accountType: string;
        connected: boolean;
        balances: number;
    },
): void {
    if (isLeavingPage()) {
        return;
    }

    captureBrokerError(
        error,
        { kind, stage: 'balances', role: details.role },
        {
            account_type: details.accountType,
            connected: details.connected,
            balances: details.balances,
        },
    );
}

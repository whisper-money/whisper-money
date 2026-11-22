import { type BrokerAccountRole } from '@/lib/broker-imports/broker-import';
import { type BrokerFileKind } from '@/lib/broker-imports/types';
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

/** Where a broker import went wrong. */
export type BrokerImportStage = 'parse' | 'accounts' | 'run' | 'balances';

/** What a finished run says, before PostHog hears of it. */
export interface BrokerRunSummary {
    kind: BrokerFileKind;
    rows: number;
    outcome: ImportOutcome;
    balancesFailed: boolean;
    /** Whether the plan has a portfolio and the user kept it. */
    portfolio: 'imported' | 'skipped' | 'n/a';
    cashConnected: boolean;
    accountsCreated: Partial<Record<BrokerAccountRole, number>>;
    reimport: boolean;
}

/** The HTTP status a failed request carries, 0 when it never got an answer. */
function responseStatus(error: unknown): number {
    const status = (error as { response?: { status?: unknown } } | null)
        ?.response?.status;

    return typeof status === 'number' ? status : 0;
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
export function trackBrokerImportCompleted(summary: BrokerRunSummary): void {
    trackImport('broker_import_completed', {
        kind: summary.kind,
        rows: summary.rows,
        success_count: summary.outcome.successCount,
        failure_count: summary.outcome.errors.length,
        uncategorized_count: summary.outcome.uncategorizedCount,
        portfolio: summary.portfolio,
        cash_connected: summary.cashConnected,
        balances_failed: summary.balancesFailed,
        accounts_created_cash: summary.accountsCreated.cash ?? 0,
        accounts_created_portfolio: summary.accountsCreated.portfolio ?? 0,
        reimport: summary.reimport,
    });
}

/**
 * `broker_import_failed` for a run that threw, and the error to Sentry when
 * somebody should look at it: a refusal (4xx) the user is told about is not.
 */
export function reportBrokerImportFailed(
    error: unknown,
    kind: BrokerFileKind,
    stage: BrokerImportStage,
    extra: Record<string, number | boolean | string>,
): void {
    const status = responseStatus(error);

    trackImport('broker_import_failed', { kind, stage, status, ...extra });

    if (!shouldReportImportError(error)) {
        return;
    }

    Sentry.captureException(error, {
        tags: { feature: 'broker_import', kind, stage },
        extra: { status, ...extra },
    });
}

/**
 * A recognised broker file its parser could not read: the broker likely
 * changed its export. Nothing of the file goes with it.
 */
export function reportBrokerParseError(
    error: unknown,
    kind: BrokerFileKind,
): void {
    Sentry.captureException(error, {
        tags: { feature: 'broker_import', kind, stage: 'parse' },
    });
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

    Sentry.captureException(error, {
        tags: {
            feature: 'broker_import',
            kind,
            stage: 'balances',
            role: details.role,
        },
        extra: {
            status: responseStatus(error),
            account_type: details.accountType,
            connected: details.connected,
            balances: details.balances,
        },
    });
}

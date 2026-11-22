import { store as storeAccount } from '@/actions/App/Http/Controllers/Settings/AccountController';
import {
    chosenAccountId,
    isBankConnected,
} from '@/lib/broker-imports/broker-accounts';
import {
    brokerMemoryInUse,
    withRememberedAccounts,
} from '@/lib/broker-imports/broker-memory';
import { detectBrokerFile } from '@/lib/broker-imports/detect';
import { parseMyInvestorCash } from '@/lib/broker-imports/myinvestor-cash';
import {
    defaultMyInvestorCashPlan,
    defaultMyInvestorSecuritiesPlan,
    myInvestorCashPreviewRows,
    myInvestorCurrency,
    type MyInvestorCashImport,
    type MyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-import';
import {
    runMyInvestorCashImport,
    runMyInvestorSecuritiesImport,
} from '@/lib/broker-imports/myinvestor-run';
import { parseMyInvestorSecurities } from '@/lib/broker-imports/myinvestor-securities';
import { readBrokerGrid } from '@/lib/broker-imports/read';
import {
    reportBrokerImportFailed,
    reportBrokerParseError,
    trackBrokerFileDetected,
    trackBrokerImportCompleted,
    type BrokerImportStage,
    type BrokerRunSummary,
} from '@/lib/broker-imports/telemetry';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    defaultTradeRepublicPlan,
    tradeRepublicCurrency,
    tradeRepublicPreviewRows,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import { runTradeRepublicImport } from '@/lib/broker-imports/trade-republic-run';
import {
    type BrokerFileKind,
    type BrokerGrid,
    type BrokerLink,
} from '@/lib/broker-imports/types';
import { type ImportOutcome } from '@/lib/transaction-import';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';
import { todayDateString } from '@/utils/date';

/**
 * A broker file the drawer imports with its own step instead of a column
 * mapping. One member per broker: each brings its parser, its step and its
 * import, and the drawer only goes through the functions here, which switch
 * on `kind`.
 */
export type BrokerImport =
    | TradeRepublicImport
    | MyInvestorCashImport
    | MyInvestorSecuritiesImport;

/** Which of a broker import's accounts an account is, e.g. its cash account. */
export type BrokerAccountRole = 'cash' | 'portfolio';

export interface BrokerRunOptions {
    /** The preview rows the user kept. */
    rows: ParsedTransaction[];
    accounts: Account[];
    categories: Category[];
    banks: Bank[];
    automationRules: AutomationRule[];
    onProgress?: (processed: number) => void;
    /** Told as soon as an account exists, so a retry uses it instead of making another. */
    onAccountCreated?: (role: BrokerAccountRole, account: Account) => void;
}

export interface BrokerRunResult {
    outcome: ImportOutcome;
    /** Some daily balances could not be written: the transactions are in regardless. */
    balancesFailed: boolean;
    /** The accounts the file went to, by role, made ones included. */
    accounts: Partial<Record<BrokerAccountRole, Account>>;
}

/** For the `default` of a switch over every broker: a new one fails to compile. */
export function unknownBroker(broker: never): never {
    // Only its kind: the rest is the user's file.
    throw new Error(
        `Unknown broker import: ${(broker as { kind?: string }).kind}`,
    );
}

/**
 * What a grid holds, by broker, with the heuristic's accounts. A file told
 * apart as a broker's that its parser then cannot read goes to Sentry: the
 * broker has likely changed its export.
 */
function brokerImportFrom(
    grid: BrokerGrid,
    accounts: Account[],
    selectedAccountId: UUID | null,
    today: string,
): BrokerImport | null {
    const kind = detectBrokerFile(grid);

    if (kind === null) {
        return null;
    }

    try {
        return parsedBrokerImport(
            kind,
            grid,
            accounts,
            selectedAccountId,
            today,
        );
    } catch (error) {
        reportBrokerParseError(error, kind);

        throw error;
    }
}

/** A recognised grid, parsed by its broker's parser. */
function parsedBrokerImport(
    kind: BrokerFileKind,
    grid: BrokerGrid,
    accounts: Account[],
    selectedAccountId: UUID | null,
    today: string,
): BrokerImport {
    switch (kind) {
        case 'trade_republic': {
            const parsed = parseTradeRepublic(grid);

            return {
                kind: 'trade_republic',
                file: parsed,
                today,
                plan: defaultTradeRepublicPlan(
                    parsed,
                    accounts,
                    selectedAccountId,
                ),
            };
        }
        case 'myinvestor_cash': {
            const parsed = parseMyInvestorCash(grid);

            return {
                kind: 'myinvestor_cash',
                file: parsed,
                today,
                plan: defaultMyInvestorCashPlan(
                    parsed,
                    accounts,
                    selectedAccountId,
                ),
            };
        }
        case 'myinvestor_securities':
            return {
                kind: 'myinvestor_securities',
                file: parseMyInvestorSecurities(grid),
                today,
                plan: defaultMyInvestorSecuritiesPlan(accounts),
            };
        default:
            return unknownBroker(kind);
    }
}

/**
 * Reads a file and, when it is an export of a broker the drawer imports,
 * what it holds and where each part goes by default: the accounts it went
 * to last time, else the ones the heuristic finds. Null for any other file,
 * which then goes through the column mapping. What was remembered is only
 * asked for once the file is a broker's. `today` is fixed here, so a drawer
 * left open past midnight keeps one day throughout.
 */
export async function readBrokerImport(
    file: File,
    accounts: Account[],
    selectedAccountId: UUID | null,
    loadLinks: () => Promise<BrokerLink[]> = async () => [],
    today: string = todayDateString(),
): Promise<BrokerImport | null> {
    const broker = brokerImportFrom(
        await readBrokerGrid(file),
        accounts,
        selectedAccountId,
        today,
    );

    if (!broker) {
        return null;
    }

    const remembered = withRememberedAccounts(
        broker,
        await loadLinks(),
        accounts,
        selectedAccountId,
    );

    trackBrokerFileDetected(
        remembered.kind,
        file.name,
        brokerMemoryInUse(remembered) !== null,
    );

    return remembered;
}

/** The account the transactions go to, when it already exists. */
export function brokerCashAccountId(broker: BrokerImport): UUID | null {
    switch (broker.kind) {
        case 'trade_republic':
        case 'myinvestor_cash':
            return chosenAccountId(broker.plan.cash);
        case 'myinvestor_securities':
            return null;
        default:
            return unknownBroker(broker);
    }
}

/**
 * Whether the file's cash goes to an account connected to the bank. The
 * sync brings that account's movements and balance, so the import leaves
 * both out and only a portfolio, when the file has one, comes in.
 */
export function brokerCashSynced(
    broker: BrokerImport,
    accounts: Account[],
): boolean {
    const cashAccountId = brokerCashAccountId(broker);

    return (
        cashAccountId !== null &&
        isBankConnected(
            accounts.find((account) => account.id === cashAccountId),
        )
    );
}

/**
 * Whether importing the file only writes a portfolio's daily balances, with
 * no transactions to pick: a securities file, or a Trade Republic one whose
 * cash is left to the bank sync.
 */
export function brokerImportsPortfolioOnly(
    broker: BrokerImport,
    accounts: Account[],
): boolean {
    switch (broker.kind) {
        case 'myinvestor_securities':
            return true;
        case 'trade_republic':
            return brokerCashSynced(broker, accounts);
        case 'myinvestor_cash':
            return false;
        default:
            return unknownBroker(broker);
    }
}

/** The import with its transactions going to an account picked elsewhere. */
export function withBrokerCashAccount(
    broker: BrokerImport,
    accountId: UUID,
): BrokerImport {
    switch (broker.kind) {
        case 'trade_republic':
            return {
                ...broker,
                plan: { ...broker.plan, cash: { kind: 'existing', accountId } },
            };
        case 'myinvestor_cash':
            return {
                ...broker,
                plan: { ...broker.plan, cash: { kind: 'existing', accountId } },
            };
        case 'myinvestor_securities':
            return broker;
        default:
            return unknownBroker(broker);
    }
}

/** The accounts each broker's plan picks, by role: a new broker must list its own. */
const ACCOUNT_ROLES: Record<
    BrokerImport['kind'],
    readonly BrokerAccountRole[]
> = {
    trade_republic: ['cash', 'portfolio'],
    myinvestor_cash: ['cash'],
    myinvestor_securities: ['portfolio'],
};

/** The import with an account it just made standing in for "create". */
export function withBrokerAccountCreated(
    broker: BrokerImport,
    role: BrokerAccountRole,
    account: Account,
): BrokerImport {
    if (!ACCOUNT_ROLES[broker.kind].includes(role)) {
        return broker;
    }

    // ACCOUNT_ROLES lists only fields this kind's plan has, so it keeps its shape.
    return {
        ...broker,
        plan: {
            ...broker.plan,
            [role]: { kind: 'existing', accountId: account.id },
        },
    } as BrokerImport;
}

/** The currency the broker file is kept in. */
export function brokerCurrency(broker: BrokerImport): string {
    switch (broker.kind) {
        case 'trade_republic':
            return tradeRepublicCurrency(broker.file);
        case 'myinvestor_cash':
        case 'myinvestor_securities':
            return myInvestorCurrency(broker.file);
        default:
            return unknownBroker(broker);
    }
}

/** The rows the preview shows for a broker file. */
export function brokerPreviewRows(broker: BrokerImport): ParsedTransaction[] {
    switch (broker.kind) {
        case 'trade_republic':
            return tradeRepublicPreviewRows(
                broker.file,
                broker.plan.balanceToday,
                broker.today,
            );
        case 'myinvestor_cash':
            return myInvestorCashPreviewRows(
                broker.file,
                broker.plan.balanceToday,
                broker.today,
            );
        case 'myinvestor_securities':
            return [];
        default:
            return unknownBroker(broker);
    }
}

/** Whether the import has a portfolio and the user kept it, for telemetry. */
function portfolioPlan(broker: BrokerImport): BrokerRunSummary['portfolio'] {
    switch (broker.kind) {
        case 'trade_republic':
            return broker.plan.portfolio.kind === 'skip'
                ? 'skipped'
                : 'imported';
        case 'myinvestor_securities':
            return 'imported';
        case 'myinvestor_cash':
            return 'n/a';
        default:
            return unknownBroker(broker);
    }
}

/** Which step a run that threw was on: making an account, or the rest. */
function failedStage(error: unknown): BrokerImportStage {
    const url = (error as { config?: { url?: unknown } } | null)?.config?.url;

    return url === storeAccount.url() ? 'accounts' : 'run';
}

/**
 * Brings a broker file in, the way its broker needs, and tells PostHog how
 * it went (and Sentry when it threw), with counts only.
 */
export async function runBrokerImport(
    broker: BrokerImport,
    options: BrokerRunOptions,
): Promise<BrokerRunResult> {
    const accountsCreated: Partial<Record<BrokerAccountRole, number>> = {};
    const reimport = brokerMemoryInUse(broker) !== null;
    const tracked: BrokerRunOptions = {
        ...options,
        onAccountCreated: (role, account) => {
            accountsCreated[role] = (accountsCreated[role] ?? 0) + 1;
            options.onAccountCreated?.(role, account);
        },
    };

    try {
        const result = await dispatchBrokerImport(broker, tracked);

        trackBrokerImportCompleted({
            kind: broker.kind,
            rows: options.rows.length,
            outcome: result.outcome,
            balancesFailed: result.balancesFailed,
            portfolio: portfolioPlan(broker),
            cashConnected: isBankConnected(result.accounts.cash),
            accountsCreated,
            reimport,
        });

        return result;
    } catch (error) {
        reportBrokerImportFailed(error, broker.kind, failedStage(error), {
            rows: options.rows.length,
            accounts_created_cash: accountsCreated.cash ?? 0,
            accounts_created_portfolio: accountsCreated.portfolio ?? 0,
            reimport,
        });

        throw error;
    }
}

function dispatchBrokerImport(
    broker: BrokerImport,
    options: BrokerRunOptions,
): Promise<BrokerRunResult> {
    switch (broker.kind) {
        case 'trade_republic':
            return runTradeRepublicImport({ ...options, broker });
        case 'myinvestor_cash':
            return runMyInvestorCashImport(broker, options);
        case 'myinvestor_securities':
            return runMyInvestorSecuritiesImport(broker, options);
        default:
            return unknownBroker(broker);
    }
}

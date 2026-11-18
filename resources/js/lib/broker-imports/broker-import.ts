import { chosenAccountId } from '@/lib/broker-imports/broker-accounts';
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
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    defaultTradeRepublicPlan,
    tradeRepublicCurrency,
    tradeRepublicPreviewRows,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import { runTradeRepublicImport } from '@/lib/broker-imports/trade-republic-run';
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
}

/** For the `default` of a switch over every broker: a new one fails to compile. */
export function unknownBroker(broker: never): never {
    // Only its kind: the rest is the user's file.
    throw new Error(
        `Unknown broker import: ${(broker as { kind?: string }).kind}`,
    );
}

/**
 * Reads a file and, when it is an export of a broker the drawer imports,
 * what it holds and where each part goes by default. Null for any other
 * file, which then goes through the column mapping. `today` is fixed here,
 * so a drawer left open past midnight keeps one day throughout.
 */
export async function readBrokerImport(
    file: File,
    accounts: Account[],
    selectedAccountId: UUID | null,
    today: string = todayDateString(),
): Promise<BrokerImport | null> {
    const grid = await readBrokerGrid(file);

    switch (detectBrokerFile(grid)) {
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
            return null;
    }
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

/** Brings a broker file in, the way its broker needs. */
export function runBrokerImport(
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

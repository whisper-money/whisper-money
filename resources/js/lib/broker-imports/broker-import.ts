import { detectBrokerFile } from '@/lib/broker-imports/detect';
import { readBrokerGrid } from '@/lib/broker-imports/read';
import { parseTradeRepublic } from '@/lib/broker-imports/trade-republic';
import {
    defaultTradeRepublicPlan,
    tradeRepublicCurrency,
    tradeRepublicPreviewRows,
    type TradeRepublicImport,
} from '@/lib/broker-imports/trade-republic-import';
import {
    runTradeRepublicImport,
    type TradeRepublicRunOptions,
    type TradeRepublicRunResult,
} from '@/lib/broker-imports/trade-republic-run';
import { type Account } from '@/types/account';
import { type ParsedTransaction } from '@/types/import';
import { type UUID } from '@/types/uuid';

/**
 * A broker file the drawer imports with its own step instead of a column
 * mapping. One member per broker: each brings its parser, its step and its
 * import, and the drawer only switches on `kind`.
 */
export type BrokerImport = TradeRepublicImport;

/**
 * Reads a file and, when it is an export of a broker the drawer imports,
 * what it holds and where each part goes by default. Null for any other
 * file, which then goes through the column mapping.
 */
export async function readBrokerImport(
    file: File,
    accounts: Account[],
    selectedAccountId: UUID | null,
): Promise<BrokerImport | null> {
    const grid = await readBrokerGrid(file);

    switch (detectBrokerFile(grid)) {
        case 'trade_republic': {
            const parsed = parseTradeRepublic(grid);

            return {
                kind: 'trade_republic',
                file: parsed,
                plan: defaultTradeRepublicPlan(
                    parsed,
                    accounts,
                    selectedAccountId,
                ),
            };
        }
        default:
            return null;
    }
}

/** The account the transactions go to, when it already exists. */
export function brokerCashAccountId(broker: BrokerImport): UUID | null {
    return broker.plan.cash.kind === 'existing'
        ? broker.plan.cash.accountId
        : null;
}

/** The currency the broker file is kept in. */
export function brokerCurrency(broker: BrokerImport): string {
    return tradeRepublicCurrency(broker.file);
}

/** The rows the preview shows for a broker file. */
export function brokerPreviewRows(
    broker: BrokerImport,
    today: string,
): ParsedTransaction[] {
    return tradeRepublicPreviewRows(
        broker.file,
        broker.plan.balanceToday,
        today,
    );
}

export type BrokerRunOptions = Omit<TradeRepublicRunOptions, 'broker'>;

/** Brings a broker file in, the way its broker needs. */
export function runBrokerImport(
    broker: BrokerImport,
    options: BrokerRunOptions,
): Promise<TradeRepublicRunResult> {
    return runTradeRepublicImport({ ...options, broker });
}

import { findHeaderRow } from '@/lib/broker-imports/grid';
import { isMyInvestorCashHeader } from '@/lib/broker-imports/myinvestor-cash';
import { isSecuritiesOrderRow } from '@/lib/broker-imports/myinvestor-securities';
import { isTradeRepublicHeader } from '@/lib/broker-imports/trade-republic';
import {
    type BrokerFileKind,
    type BrokerGrid,
} from '@/lib/broker-imports/types';

/**
 * Which broker export a file is, from its grid, or null for any other file.
 * Trade Republic is told by its header; the MyInvestor securities export by
 * rows anchored on an ISIN next to an order type, since its header is
 * misaligned; the current-account export by a header found below its title
 * rows.
 */
export function detectBrokerFile(grid: BrokerGrid): BrokerFileKind | null {
    if (findHeaderRow(grid, isTradeRepublicHeader) !== -1) {
        return 'trade_republic';
    }

    if (grid.some(isSecuritiesOrderRow)) {
        return 'myinvestor_securities';
    }

    return findHeaderRow(grid, isMyInvestorCashHeader) !== -1
        ? 'myinvestor_cash'
        : null;
}

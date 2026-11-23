import { findHeaderRow } from '@/lib/broker-imports/grid';
import { readBrokerGrid } from '@/lib/broker-imports/read';
import { type BrokerGrid } from '@/lib/broker-imports/types';

/**
 * The columns of the orders list MyInvestor exports from its "Órdenes" page,
 * normalised: order date, ISIN, estimated amount, shares and status.
 */
const ORDERS_HEADER = ['fecha de la orden', 'isin', 'importe estimado'];

/**
 * Whether the grid is MyInvestor's orders list rather than its securities
 * operations export (`salida.xls`). The list carries no order type and an
 * unsigned amount, so a buy cannot be told from a redemption: it is
 * recognised only to tell the user to download the other file.
 */
export function isMyInvestorOrdersList(grid: BrokerGrid): boolean {
    return (
        findHeaderRow(grid, (names) =>
            ORDERS_HEADER.every((name) => names.includes(name)),
        ) !== -1
    );
}

/** `isMyInvestorOrdersList` for a file, false when it cannot be read. */
export async function isMyInvestorOrdersFile(file: File): Promise<boolean> {
    try {
        return isMyInvestorOrdersList(await readBrokerGrid(file));
    } catch {
        return false;
    }
}

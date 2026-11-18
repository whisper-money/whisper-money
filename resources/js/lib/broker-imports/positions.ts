import { type Position, type Trade } from '@/lib/broker-imports/types';

/** One change to a holding: a trade, or shares received without paying. */
export type PositionChange = Omit<Position, 'shares' | 'invested'> & {
    /** Signed. */
    shares: number;
    /** Signed, in minor units. */
    invested: number;
};

/** A trade as the change it makes to its holding. */
export function tradeChange(trade: Trade): PositionChange {
    const sign = trade.side === 'buy' ? 1 : -1;

    return {
        symbol: trade.symbol,
        name: trade.name,
        assetClass: trade.assetClass,
        currency: trade.currency,
        shares: sign * trade.shares,
        invested: sign * trade.amount,
    };
}

/** Shares rounded away from float noise, so a closed position reads 0. */
function roundShares(shares: number): number {
    return Math.round(shares * 1e10) / 1e10;
}

/** What is held of each symbol, in the order the symbols first appear. */
export function positionsFrom(changes: PositionChange[]): Position[] {
    const bySymbol = new Map<string, Position>();

    for (const change of changes) {
        const position = bySymbol.get(change.symbol) ?? {
            ...change,
            shares: 0,
            invested: 0,
        };

        position.shares = roundShares(position.shares + change.shares);
        position.invested += change.invested;
        bySymbol.set(change.symbol, position);
    }

    return [...bySymbol.values()];
}

/** Cost taken out of a fund by a transfer, waiting for the fund it went into. */
interface TransferredCost {
    /** What the outgoing leg sold for, still unclaimed, in minor units. */
    value: number;
    /** The cost basis that went with it, still unclaimed, in minor units. */
    cost: number;
}

/**
 * Takes cost out of the queue for an incoming leg worth `value`, first in
 * first out, in proportion to the value each outgoing leg sold for. What the
 * queue cannot cover (the outgoing leg is not in the file) costs its value.
 */
function claimCost(queue: TransferredCost[], value: number): number {
    let missing = value;
    let cost = 0;

    while (missing > 0 && queue.length > 0) {
        const leg = queue[0];
        const taken = Math.min(missing, leg.value);
        const share = Math.round((leg.cost * taken) / leg.value);

        cost += share;
        leg.cost -= share;
        leg.value -= taken;
        missing -= taken;

        if (leg.value <= 0) {
            queue.shift();
        }
    }

    return cost + missing;
}

/**
 * The holdings after a list of trades in which transfers between funds
 * (traspasos, switches) carry their cost basis across instead of their market
 * value: the outgoing fund gives up the average cost of the shares it sold,
 * and the incoming fund starts from that cost. What was invested in total
 * does not move. Trades are taken in date order, outgoing legs of a day first.
 */
export function positionsCarryingTransfers(trades: Trade[]): Position[] {
    const ordered = [...trades].sort(
        (a, b) =>
            a.date.localeCompare(b.date) ||
            Number(a.side === 'buy') - Number(b.side === 'buy'),
    );
    const held = new Map<string, { shares: number; invested: number }>();
    const queue: TransferredCost[] = [];

    const changes = ordered.map((trade) => {
        const change = tradeChange(trade);
        const holding = held.get(trade.symbol) ?? { shares: 0, invested: 0 };

        if (trade.isTransfer && trade.side === 'sell') {
            const fraction =
                holding.shares > 0
                    ? Math.min(1, trade.shares / holding.shares)
                    : 1;
            const cost = Math.round(holding.invested * fraction);

            queue.push({ value: trade.amount, cost });
            change.invested = -cost;
        } else if (trade.isTransfer) {
            change.invested = claimCost(queue, trade.amount);
        }

        held.set(trade.symbol, {
            shares: holding.shares + change.shares,
            invested: holding.invested + change.invested,
        });

        return change;
    });

    const order = [...new Set(trades.map((trade) => trade.symbol))];

    return positionsFrom(changes).sort(
        (a, b) => order.indexOf(a.symbol) - order.indexOf(b.symbol),
    );
}

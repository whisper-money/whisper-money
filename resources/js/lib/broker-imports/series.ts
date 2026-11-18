import { type CashMovement, type Trade } from '@/lib/broker-imports/types';

const DAY_MS = 86_400_000;

function dayNumber(day: string): number {
    return Date.parse(`${day}T00:00:00Z`) / DAY_MS;
}

function dayOf(number: number): string {
    return new Date(number * DAY_MS).toISOString().slice(0, 10);
}

/** Every day from `first` to `last`, both included, as `YYYY-MM-DD`. */
function daysBetween(first: string, last: string): string[] {
    const days: string[] = [];

    for (let day = dayNumber(first); day <= dayNumber(last); day++) {
        days.push(dayOf(day));
    }

    return days;
}

/** The sum of each day's amounts, by day. */
function totalsByDay<T extends { date: string }>(
    items: T[],
    amountOf: (item: T) => number,
): Map<string, number> {
    const totals = new Map<string, number>();

    for (const item of items) {
        totals.set(item.date, (totals.get(item.date) ?? 0) + amountOf(item));
    }

    return totals;
}

function earliest(items: { date: string }[]): string | null {
    return items.reduce<string | null>(
        (first, item) =>
            first === null || item.date < first ? item.date : first,
        null,
    );
}

/**
 * What had been invested at the end of each day, buys minus sells, from the
 * first trade up to `today` (or the last trade, if that is later).
 */
export function dailyInvested(
    trades: Trade[],
    today: string,
): { date: string; investedMinor: number }[] {
    const first = earliest(trades);

    if (first === null) {
        return [];
    }

    const changes = totalsByDay(trades, (trade) =>
        trade.side === 'buy' ? trade.amount : -trade.amount,
    );
    const last = [...changes.keys(), today].sort().at(-1) ?? today;
    let invested = 0;

    return daysBetween(first, last).map((date) => {
        invested += changes.get(date) ?? 0;

        return { date, investedMinor: invested };
    });
}

/**
 * The cash balance at the end of each day, from the first movement up to
 * `today`, rebuilt backwards from today's balance: a file that does not start
 * at account opening still ends where the account is. Movements dated after
 * today are left out: today's balance does not hold them yet.
 */
export function cashBalances(
    movements: CashMovement[],
    balanceToday: number,
    today: string,
): { date: string; balanceMinor: number }[] {
    const first = earliest(movements);

    if (first === null) {
        return [];
    }

    const changes = totalsByDay(movements, (movement) => movement.amount);
    let balance = balanceToday;
    const points = daysBetween(first < today ? first : today, today)
        .reverse()
        .map((date) => {
            const point = { date, balanceMinor: balance };
            balance -= changes.get(date) ?? 0;

            return point;
        });

    return points.reverse();
}

/** The balance a file adds up to, for a file that starts when the account opened. */
export function computedCashBalance(movements: CashMovement[]): number {
    return movements.reduce((sum, movement) => sum + movement.amount, 0);
}

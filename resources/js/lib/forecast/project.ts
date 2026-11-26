import {
    daysInMonth,
    formatDate,
    intdiv,
    monthKey,
    monthStart,
    parseDate,
} from './calendar';
import type {
    ForecastOccurrence,
    MonthlyEstimate,
    ProjectedMonth,
    Projection,
    ProjectionAccount,
    ProjectionInput,
} from './types';

/**
 * Port of app/Services/Forecast/ForecastProjector.php: projects each
 * account's balance day by day and sums them into a total, month by month.
 * The server's figures win; this runs so an edit shows at once.
 * tests/Fixtures/forecast-parity.json keeps the two in step.
 */

/** The band is 2/5 (40%) of the month's estimate. */
const BAND_NUMERATOR = 2;
const BAND_DENOMINATOR = 5;

interface CalendarMonth {
    key: string;
    year: number;
    month: number;
    firstDay: number;
    lastDay: number;
}

interface DailyFlow {
    income: number;
    expenses: number;
    transfers: number;
}

class MonthAccumulator {
    min: number | null = null;
    minDate: string | null = null;
    close = 0;
    income = 0;
    concreteExpenses = 0;
    transfers = 0;
    growth = 0;
    estimated = 0;
    readonly balances = new Map<string, number>();

    constructor(readonly month: string) {}

    observe(date: string, balance: number): void {
        this.balances.set(date, balance);
        this.close = balance;

        if (this.min === null || balance < this.min) {
            this.min = balance;
            this.minDate = date;
        }
    }

    addFlow(flow: DailyFlow | undefined): number {
        if (!flow) {
            return 0;
        }

        this.income += flow.income;
        this.concreteExpenses += flow.expenses;
        this.transfers += flow.transfers;

        return flow.income - flow.expenses + flow.transfers;
    }

    absorbFlows(other: MonthAccumulator): void {
        this.income += other.income;
        this.concreteExpenses += other.concreteExpenses;
        this.transfers += other.transfers;
        this.growth += other.growth;
        this.estimated += other.estimated;
    }

    toProjectedMonth(): ProjectedMonth {
        const min = this.min ?? this.close;
        const band = intdiv(this.estimated * BAND_NUMERATOR, BAND_DENOMINATOR);

        return {
            month: this.month,
            min,
            minDate: this.minDate,
            close: this.close,
            income: this.income,
            expenses: this.concreteExpenses + this.estimated,
            transfers: this.transfers,
            growth: this.growth,
            estimated: this.estimated,
            band,
            pessimisticMin: min - band,
        };
    }
}

function calendarMonths(
    startDate: string,
    horizonMonths: number,
): CalendarMonth[] {
    return Array.from({ length: Math.max(horizonMonths, 0) }, (_, offset) => {
        const first = offset === 0 ? startDate : monthStart(startDate, offset);
        const [year, month, day] = parseDate(first);

        return {
            key: monthKey(first),
            year,
            month,
            firstDay: day,
            lastDay: daysInMonth(year, month),
        };
    });
}

function addFlow(
    flows: Map<string, Map<string, DailyFlow>>,
    accountId: string,
    date: string,
    change: (flow: DailyFlow) => void,
): void {
    const byDate = flows.get(accountId) ?? new Map<string, DailyFlow>();
    const flow = byDate.get(date) ?? { income: 0, expenses: 0, transfers: 0 };
    change(flow);
    byDate.set(date, flow);
    flows.set(accountId, byDate);
}

/** Each account's concrete flows by day, in one pass: a transfer counts on both its accounts. */
function flowsByAccount(
    occurrences: ForecastOccurrence[],
): Map<string, Map<string, DailyFlow>> {
    const flows = new Map<string, Map<string, DailyFlow>>();

    for (const occurrence of occurrences) {
        const destination = occurrence.destinationAccountId;

        addFlow(flows, occurrence.accountId, occurrence.date, (flow) => {
            if (destination !== null) {
                flow.transfers += occurrence.amount;
            } else if (occurrence.amount >= 0) {
                flow.income += occurrence.amount;
            } else {
                flow.expenses -= occurrence.amount;
            }
        });

        if (destination !== null) {
            addFlow(flows, destination, occurrence.date, (flow) => {
                flow.transfers -= occurrence.amount;
            });
        }
    }

    return flows;
}

/** Estimates by account, then month; projectAccount floors each month's sum at zero. */
function estimatesByAccount(
    estimates: MonthlyEstimate[],
): Map<string, Map<string, number>> {
    const byAccount = new Map<string, Map<string, number>>();

    for (const estimate of estimates) {
        const byMonth = byAccount.get(estimate.accountId) ?? new Map();
        byMonth.set(
            estimate.month,
            (byMonth.get(estimate.month) ?? 0) + estimate.amount,
        );
        byAccount.set(estimate.accountId, byMonth);
    }

    return byAccount;
}

/** The `day`-th of `days` even shares of the estimate; the shares add up exactly. */
function estimateShare(estimate: number, day: number, days: number): number {
    return (
        Math.floor((estimate * day) / days) -
        Math.floor((estimate * (day - 1)) / days)
    );
}

function projectMonth(
    account: ProjectionAccount,
    month: CalendarMonth,
    openingBalance: number,
    flows: Map<string, DailyFlow>,
    estimate: number,
): MonthAccumulator {
    const accumulator = new MonthAccumulator(month.key);
    accumulator.estimated = estimate;
    const days = month.lastDay - month.firstDay + 1;
    let balance = openingBalance;

    for (let day = month.firstDay; day <= month.lastDay; day++) {
        const date = formatDate(month.year, month.month, day);
        balance += accumulator.addFlow(flows.get(date));
        balance -= estimateShare(estimate, day - month.firstDay + 1, days);

        if (day === month.lastDay && month.firstDay === 1) {
            accumulator.growth = intdiv(
                balance * (account.annualRateBps ?? 0),
                120_000,
            );
            balance += accumulator.growth;
        }

        accumulator.observe(date, balance);
    }

    return accumulator;
}

function projectAccount(
    account: ProjectionAccount,
    months: CalendarMonth[],
    flows: Map<string, DailyFlow>,
    estimates: Map<string, number>,
): MonthAccumulator[] {
    let balance = account.openingBalance;

    return months.map((month) => {
        const accumulator = projectMonth(
            account,
            month,
            balance,
            flows,
            Math.max(estimates.get(month.key) ?? 0, 0),
        );
        balance = accumulator.close;

        return accumulator;
    });
}

function total(
    months: CalendarMonth[],
    perAccount: MonthAccumulator[][],
): MonthAccumulator[] {
    return months.map((month, index) => {
        const accumulator = new MonthAccumulator(month.key);
        const balances = new Map<string, number>();

        for (const accumulators of perAccount) {
            accumulator.absorbFlows(accumulators[index]);

            for (const [date, balance] of accumulators[index].balances) {
                balances.set(date, (balances.get(date) ?? 0) + balance);
            }
        }

        for (const [date, balance] of balances) {
            accumulator.observe(date, balance);
        }

        return accumulator;
    });
}

/**
 * Projects every account from its opening balance through the start month
 * and the `horizonMonths - 1` months after it.
 */
export function projectForecast(input: ProjectionInput): Projection {
    const months = calendarMonths(input.startDate, input.horizonMonths);
    const flows = flowsByAccount(input.occurrences);
    const estimates = estimatesByAccount(input.estimates ?? []);
    const perAccount = input.accounts.map((account) =>
        projectAccount(
            account,
            months,
            flows.get(account.id) ?? new Map(),
            estimates.get(account.id) ?? new Map(),
        ),
    );

    return {
        accounts: input.accounts.map((account, index) => ({
            accountId: account.id,
            minimumBalance: account.minimumBalance ?? null,
            months: perAccount[index].map((month) => month.toProjectedMonth()),
        })),
        total: total(months, perAccount).map((month) =>
            month.toProjectedMonth(),
        ),
    };
}

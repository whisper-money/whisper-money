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

class MonthSummary {
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

    absorbFlows(other: MonthSummary): void {
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

function dailyFlows(
    accountId: string,
    occurrences: ForecastOccurrence[],
): Map<string, DailyFlow> {
    const flows = new Map<string, DailyFlow>();

    for (const occurrence of occurrences) {
        const flow = flows.get(occurrence.date) ?? {
            income: 0,
            expenses: 0,
            transfers: 0,
        };
        flows.set(occurrence.date, flow);
        const isTransfer = occurrence.destinationAccountId !== null;

        if (occurrence.accountId === accountId) {
            if (isTransfer) {
                flow.transfers += occurrence.amount;
            } else if (occurrence.amount >= 0) {
                flow.income += occurrence.amount;
            } else {
                flow.expenses -= occurrence.amount;
            }
        }

        if (occurrence.destinationAccountId === accountId) {
            flow.transfers -= occurrence.amount;
        }
    }

    return flows;
}

function estimatesByMonth(
    accountId: string,
    estimates: MonthlyEstimate[],
): Map<string, number> {
    const byMonth = new Map<string, number>();

    for (const estimate of estimates) {
        if (estimate.accountId === accountId) {
            byMonth.set(
                estimate.month,
                (byMonth.get(estimate.month) ?? 0) + estimate.amount,
            );
        }
    }

    return byMonth;
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
): MonthSummary {
    const summary = new MonthSummary(month.key);
    summary.estimated = estimate;
    const days = month.lastDay - month.firstDay + 1;
    let balance = openingBalance;

    for (let day = month.firstDay; day <= month.lastDay; day++) {
        const date = formatDate(month.year, month.month, day);
        balance += summary.addFlow(flows.get(date));
        balance -= estimateShare(estimate, day - month.firstDay + 1, days);

        if (day === month.lastDay && month.firstDay === 1) {
            summary.growth = intdiv(
                balance * (account.annualRateBps ?? 0),
                120_000,
            );
            balance += summary.growth;
        }

        summary.observe(date, balance);
    }

    return summary;
}

function projectAccount(
    account: ProjectionAccount,
    months: CalendarMonth[],
    input: ProjectionInput,
): MonthSummary[] {
    const flows = dailyFlows(account.id, input.occurrences);
    const estimates = estimatesByMonth(account.id, input.estimates ?? []);
    let balance = account.openingBalance;

    return months.map((month) => {
        const summary = projectMonth(
            account,
            month,
            balance,
            flows,
            estimates.get(month.key) ?? 0,
        );
        balance = summary.close;

        return summary;
    });
}

function total(
    months: CalendarMonth[],
    perAccount: MonthSummary[][],
): MonthSummary[] {
    return months.map((month, index) => {
        const summary = new MonthSummary(month.key);
        const balances = new Map<string, number>();

        for (const summaries of perAccount) {
            summary.absorbFlows(summaries[index]);

            for (const [date, balance] of summaries[index].balances) {
                balances.set(date, (balances.get(date) ?? 0) + balance);
            }
        }

        for (const [date, balance] of balances) {
            summary.observe(date, balance);
        }

        return summary;
    });
}

/**
 * Projects every account from its opening balance through the start month
 * and the `horizonMonths - 1` months after it.
 */
export function projectForecast(input: ProjectionInput): Projection {
    const months = calendarMonths(input.startDate, input.horizonMonths);
    const perAccount = input.accounts.map((account) =>
        projectAccount(account, months, input),
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

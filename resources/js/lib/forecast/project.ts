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
    MonthlyEstimateKind,
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

/** The band is 2/5 (40%) of the estimated spend plus 1/10 (10%) of the estimated income. */
const EXPENSE_BAND_NUMERATOR = 2;
const EXPENSE_BAND_DENOMINATOR = 5;
const INCOME_BAND_DENOMINATOR = 10;

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
    estimatedIncome = 0;
    /** The uncertainty of a balance trend, added to the band but not to the pessimistic minimum. */
    trendBand = 0;
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
        this.estimatedIncome += other.estimatedIncome;
        this.trendBand += other.trendBand;
    }

    addEstimate(kind: MonthlyEstimateKind, amount: number): void {
        if (kind === 'income') {
            this.estimatedIncome += amount;
        } else {
            this.estimated += amount;
        }
    }

    toProjectedMonth(): ProjectedMonth {
        const min = this.min ?? this.close;
        const estimateBand =
            intdiv(
                this.estimated * EXPENSE_BAND_NUMERATOR,
                EXPENSE_BAND_DENOMINATOR,
            ) + intdiv(this.estimatedIncome, INCOME_BAND_DENOMINATOR);

        return {
            month: this.month,
            min,
            minDate: this.minDate,
            close: this.close,
            income: this.income + this.estimatedIncome,
            expenses: this.concreteExpenses + this.estimated,
            transfers: this.transfers,
            growth: this.growth,
            estimated: this.estimated,
            estimatedIncome: this.estimatedIncome,
            band: estimateBand + this.trendBand,
            pessimisticMin: min - estimateBand,
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

/** Estimates by account, then month. */
function estimatesByAccount(
    estimates: MonthlyEstimate[],
): Map<string, Map<string, MonthlyEstimate[]>> {
    const byAccount = new Map<string, Map<string, MonthlyEstimate[]>>();

    for (const estimate of estimates) {
        const byMonth = byAccount.get(estimate.accountId) ?? new Map();
        byMonth.set(estimate.month, [
            ...(byMonth.get(estimate.month) ?? []),
            estimate,
        ]);
        byAccount.set(estimate.accountId, byMonth);
    }

    return byAccount;
}

/** A day's profile weight; the month's last day also takes the days a short month lacks. */
function profileWeight(
    profile: number[],
    day: number,
    lastDay: number,
): number {
    if (day < lastDay) {
        return Math.max(profile[day - 1] ?? 0, 0);
    }

    return profile
        .slice(day - 1)
        .reduce((sum, weight) => sum + Math.max(weight, 0), 0);
}

/**
 * How an estimate weighs each day the month covers: all on its day for a
 * lump, by its profile otherwise, evenly when that leaves no weight.
 */
function estimateWeights(
    estimate: MonthlyEstimate,
    month: CalendarMonth,
): Map<number, number> {
    if (estimate.day !== undefined && estimate.day !== null) {
        const day = Math.max(
            Math.min(estimate.day, month.lastDay),
            month.firstDay,
        );

        return new Map([[day, 1]]);
    }

    const profile = estimate.profile ?? null;
    const weights = new Map<number, number>();
    let total = 0;

    for (let day = month.firstDay; day <= month.lastDay; day++) {
        const weight =
            profile === null ? 1 : profileWeight(profile, day, month.lastDay);
        weights.set(day, weight);
        total += weight;
    }

    if (total === 0) {
        for (const day of weights.keys()) {
            weights.set(day, 1);
        }
    }

    return weights;
}

/** The amount split by the weights: the running total is floored each day, so the shares add up exactly. */
function spread(
    amount: number,
    weights: Map<number, number>,
): Map<number, number> {
    const total = [...weights.values()].reduce((sum, weight) => sum + weight);
    const shares = new Map<number, number>();
    let running = 0;

    for (const [day, weight] of weights) {
        const before = running;
        running += weight;
        shares.set(
            day,
            intdiv(amount * running, total) - intdiv(amount * before, total),
        );
    }

    return shares;
}

/**
 * What the month's estimates change the balance by on each day (income up,
 * spend down), recording their totals on the month. A month never expects
 * less than nothing from an estimate.
 */
function estimatedByDay(
    month: CalendarMonth,
    estimates: MonthlyEstimate[],
    accumulator: MonthAccumulator,
): Map<number, number> {
    const byDay = new Map<number, number>();

    for (const estimate of estimates) {
        const amount = Math.max(estimate.amount, 0);
        const kind = estimate.kind ?? 'expense';
        accumulator.addEstimate(kind, amount);

        for (const [day, share] of spread(
            amount,
            estimateWeights(estimate, month),
        )) {
            byDay.set(
                day,
                (byDay.get(day) ?? 0) + (kind === 'income' ? share : -share),
            );
        }
    }

    return byDay;
}

/**
 * The account's balance trend, or else a twelfth of its yearly rate on the
 * month's close, truncated to whole minor units.
 */
function monthlyGrowth(balance: number, account: ProjectionAccount): number {
    return (
        account.trendSlope ??
        intdiv(balance * (account.annualRateBps ?? 0), 120_000)
    );
}

function projectMonth(
    account: ProjectionAccount,
    month: CalendarMonth,
    openingBalance: number,
    flows: Map<string, DailyFlow>,
    estimates: MonthlyEstimate[],
): MonthAccumulator {
    const accumulator = new MonthAccumulator(month.key);
    const estimated = estimatedByDay(month, estimates, accumulator);
    let balance = openingBalance;

    for (let day = month.firstDay; day <= month.lastDay; day++) {
        const date = formatDate(month.year, month.month, day);
        balance += accumulator.addFlow(flows.get(date));
        balance += estimated.get(day) ?? 0;

        if (day === month.lastDay && month.firstDay === 1) {
            accumulator.growth = monthlyGrowth(balance, account);
            accumulator.trendBand = account.trendBand ?? 0;
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
    estimates: Map<string, MonthlyEstimate[]>,
): MonthAccumulator[] {
    let balance = account.openingBalance;

    return months.map((month) => {
        const accumulator = projectMonth(
            account,
            month,
            balance,
            flows,
            estimates.get(month.key) ?? [],
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

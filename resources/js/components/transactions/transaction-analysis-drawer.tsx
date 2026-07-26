import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import {
    CategoryBreakdownRow,
    type CategoryBreakdownAdapter,
} from '@/components/shared/category-breakdown-list';
import { LabelBadges } from '@/components/shared/label-combobox';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartConfig, ChartContainer } from '@/components/ui/chart';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import { Input } from '@/components/ui/input';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useExpandableCategories } from '@/hooks/use-expandable-categories';
import { useLocale } from '@/hooks/use-locale';
import {
    filtersFingerprint,
    serializeFilters,
    type SerializedFilters,
} from '@/lib/transaction-filter-serialization';
import { cn } from '@/lib/utils';
import {
    getCategoryColorClasses,
    type CategoryColor,
    type CategoryIcon,
} from '@/types/category';
import { type Label } from '@/types/label';
import { type TransactionFilters } from '@/types/transaction';
import { type UUID } from '@/types/uuid';
import { formatDate, formatMonthFromYearMonth } from '@/utils/date';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { format, parseISO } from 'date-fns';
import * as Icons from 'lucide-react';
import {
    Check,
    HelpCircle,
    Minus,
    Settings2,
    SlidersHorizontal,
    TrendingDown,
    TrendingUp,
    type LucideIcon,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import {
    Bar,
    Cell,
    ComposedChart,
    Line,
    ReferenceLine,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type AnalysisMode = 'expense' | 'income' | 'trend';

/**
 * The two bounded shapes, where a total and a daily average are the answer.
 * The trend view is never one of them, and typing the bounded widgets against
 * this keeps that guarantee at the compiler instead of in a fallthrough.
 */
type BoundedMode = Exclude<AnalysisMode, 'trend'>;

/**
 * Income only changes the analysis into its income-and-expense shape once it
 * is a meaningful share of the spending; a stray refund should not flip a trip
 * into a profit-and-loss view.
 */
const INCOME_MODE_THRESHOLD = 0.15;

/**
 * Past this span a filter has stopped describing a bounded thing like a trip
 * or a project: the total and the daily average answer nothing, so the
 * analysis switches to a monthly rate and its direction. Four months is the
 * shortest span where the recent-months average covers less than the whole
 * set, which is what makes the comparison between the two mean anything.
 */
const TREND_MIN_DAYS = 120;

/** How many completed months the recent-rate card averages over. */
const RECENT_MONTHS = 3;

function hasSignificantIncome(income: number, expense: number): boolean {
    return income > 0 && income >= expense * INCOME_MODE_THRESHOLD;
}

function detectMode(
    income: number,
    expense: number,
    days: number,
): AnalysisMode {
    if (days >= TREND_MIN_DAYS) {
        return 'trend';
    }

    return hasSignificantIncome(income, expense) ? 'income' : 'expense';
}

/** Compact axis ticks: an amount in cents in, "1.2K" out. */
function compactAmount(value: number, locale: string): string {
    return new Intl.NumberFormat(locale, {
        notation: 'compact',
        compactDisplay: 'short',
    }).format(value / 100);
}

function modeLabel(mode: AnalysisMode): string {
    switch (mode) {
        case 'expense':
            return __('Total spent');
        case 'income':
            return __('Income & expenses');
        case 'trend':
            return __('Monthly trend');
    }
}

interface AnalysisSummary {
    income: number;
    expense: number;
    net: number;
    count: number;
    days: number;
    average_expense_per_day: number;
    /** The day the series starts on, or null when nothing matched. */
    first_date: string | null;
}

interface CategorySlice {
    category_id: string | null;
    name: string;
    color: string;
    icon: string | null;
    amount: number;
    children: CategorySlice[];
}

interface TagSlice {
    id: string;
    name: string;
    color: string;
    amount: number;
}

interface PayeeSlice {
    name: string;
    amount: number;
}

interface AccountSlice {
    id: string | null;
    name: string;
    bank: { name: string; logo: string | null } | null;
    amount: number;
}

interface LargestExpense {
    id: string;
    date: string;
    description: string | null;
    amount: number;
    category: {
        name: string;
        color: string | null;
        icon: string | null;
    } | null;
    account: {
        name: string;
        bank: { name: string; logo: string | null } | null;
    } | null;
    labels: { id: string; name: string; color: string }[];
}

/**
 * One bucket of the over-time series. The endpoint walks a cursor from the
 * first transaction to the last and fills empty buckets with zeroes, so the
 * series is chronological and gapless — which is what lets the monthly
 * derivations below average by position and read the span off the ends.
 */
interface OverTimePoint {
    date: string;
    label: string;
    income: number;
    expense: number;
    cumulative_expense: number;
    cumulative_net: number;
}

interface AnalysisData {
    currency: string;
    summary: AnalysisSummary;
    by_category: CategorySlice[];
    distinct_category_count: number;
    by_tag: TagSlice[];
    distinct_label_count: number;
    by_payee: PayeeSlice[];
    distinct_payee_count: number;
    by_account: AccountSlice[];
    distinct_account_count: number;
    largest_expenses: LargestExpense[];
    over_time: { bucket: 'day' | 'month'; points: OverTimePoint[] };
}

interface MonthlyPoint {
    key: string;
    label: string;
    income: number;
    expense: number;
    net: number;
}

interface MonthlyRates {
    average: number;
    /**
     * Null while the whole months do not outnumber the recent window, when the
     * two averages would be the same number shown twice.
     */
    recentAverage: number | null;
    changePercentage: number | null;
    /** How many whole months the average covers, for the card to own up to. */
    months: number;
}

/**
 * Folds the over-time series into calendar months. The series arrives bucketed
 * by day for short spans and by month for long ones, and the trend view needs
 * months either way — including when the user forces it onto a span the
 * backend bucketed by day.
 */
function toMonthlyPoints(
    points: OverTimePoint[],
    locale: string,
): MonthlyPoint[] {
    const months = new Map<string, MonthlyPoint>();

    for (const point of points) {
        const key = point.date.slice(0, 7);
        const month = months.get(key);

        if (month) {
            month.income += point.income;
            month.expense += point.expense;
            month.net += point.income - point.expense;
            continue;
        }

        months.set(key, {
            key,
            label: formatMonthFromYearMonth(key, locale),
            income: point.income,
            expense: point.expense,
            net: point.income - point.expense,
        });
    }

    return [...months.values()];
}

/**
 * Whether the series only covers part of this month, which is what keeps it out
 * of every monthly figure.
 *
 * Either edge of the span can be a fraction of a month and both would drag an
 * average down. The trailing one is the calendar month still in progress — on
 * the 2nd it holds two days of spending, which would read as a drop that has
 * not happened. The leading one is the month the series starts in, whenever
 * that is not its 1st: a filter clipping a few days out of March, or simply the
 * first transaction landing on the 18th, leaves a month that never had a chance
 * to spend a full month's worth.
 */
export function isPartialMonth(key: string, firstDate: string | null): boolean {
    if (key >= format(new Date(), 'yyyy-MM')) {
        return true;
    }

    return (
        firstDate !== null &&
        !firstDate.endsWith('-01') &&
        key === firstDate.slice(0, 7)
    );
}

/**
 * The monthly rate and how the recent months compare against it.
 */
export function monthlyRates(
    months: MonthlyPoint[],
    useNet: boolean,
    firstDate: string | null = null,
): MonthlyRates | null {
    const whole = months.filter(
        (month) => !isPartialMonth(month.key, firstDate),
    );

    if (whole.length === 0) {
        return null;
    }

    const value = (month: MonthlyPoint) => (useNet ? month.net : month.expense);
    const mean = (subset: MonthlyPoint[]) =>
        Math.round(
            subset.reduce((sum, month) => sum + value(month), 0) /
                subset.length,
        );

    const average = mean(whole);

    if (whole.length <= RECENT_MONTHS) {
        return {
            average,
            recentAverage: null,
            changePercentage: null,
            months: whole.length,
        };
    }

    const recentAverage = mean(whole.slice(-RECENT_MONTHS));

    return {
        average,
        recentAverage,
        changePercentage:
            average === 0
                ? null
                : Math.round(
                      ((recentAverage - average) / Math.abs(average)) * 100,
                  ),
        months: whole.length,
    };
}

interface AnalysisView {
    /** The shape actually on screen, which the view toggle's trigger names. */
    resolvedMode: AnalysisMode;
    /** The bounded shape to render whenever the trend one is not on screen. */
    boundedMode: BoundedMode;
    /** Non-null exactly when the trend view has something to average. */
    trendRates: MonthlyRates | null;
    /** Whether income is a big enough share to report net figures. */
    showsIncome: boolean;
}

/**
 * Settles which analysis shape is on screen. Trend is a request rather than a
 * guarantee: it needs at least one completed calendar month to average, so a
 * span without one falls back to the bounded shape, toggle label included.
 */
export function resolveAnalysisView(
    effectiveMode: AnalysisMode,
    income: number,
    expense: number,
    months: MonthlyPoint[],
    firstDate: string | null = null,
): AnalysisView {
    const showsIncome = hasSignificantIncome(income, expense);
    const isTrend = effectiveMode === 'trend';

    const boundedMode: BoundedMode = isTrend
        ? showsIncome
            ? 'income'
            : 'expense'
        : effectiveMode;
    const trendRates = isTrend
        ? monthlyRates(months, showsIncome, firstDate)
        : null;

    return {
        resolvedMode: trendRates ? 'trend' : boundedMode,
        boundedMode,
        trendRates,
        showsIncome,
    };
}

/**
 * Whether a change in the monthly rate is bad news: a rising monthly spend is,
 * while a rising net result is the opposite.
 */
export function isAdverseChange(
    change: number | null,
    showsIncome: boolean,
): boolean {
    if (change === null || change === 0) {
        return false;
    }

    return showsIncome ? change < 0 : change > 0;
}

interface TransactionAnalysisDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    filters: TransactionFilters;
}

function buildQueryString(filters: SerializedFilters): string {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (Array.isArray(value)) {
            if (value.length > 0) {
                params.set(key, value.join(','));
            }
        } else if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    });

    return params.toString();
}

interface SavedFilterSummary {
    id: UUID;
    filters: SerializedFilters;
    analysis_days: number | null;
    analysis_mode: AnalysisMode | null;
}

const DAY_OVERRIDE_STORAGE_PREFIX = 'wm.analysis-days.';
const MODE_OVERRIDE_STORAGE_PREFIX = 'wm.analysis-mode.';

function readStoredDays(key: string): number | null {
    const raw = localStorage.getItem(key);
    if (raw === null) {
        return null;
    }
    const parsed = Number.parseInt(raw, 10);
    return Number.isFinite(parsed) && parsed > 0 ? parsed : null;
}

const ANALYSIS_MODES: AnalysisMode[] = ['expense', 'income', 'trend'];

function readStoredMode(key: string): AnalysisMode | null {
    const raw = localStorage.getItem(key);

    return ANALYSIS_MODES.find((mode) => mode === raw) ?? null;
}

/**
 * Carries the two overrides a filter set can hold: the day span behind the
 * daily average, and the analysis view. Both are remembered per filter
 * fingerprint in the browser and, when the current filters match a saved
 * filter, synced to the backend. A single lookup of the saved filters backs
 * both, so the drawer hits the API once per open.
 *
 * Which view an absent override resolves to is the caller's business — it
 * depends on the effective day span, which this hook is what supplies.
 */
function useAnalysisPreferences(
    open: boolean,
    filters: TransactionFilters,
    autoDays: number,
) {
    const fingerprint = useMemo(
        () => filtersFingerprint(serializeFilters(filters)),
        [filters],
    );
    const dayKey = `${DAY_OVERRIDE_STORAGE_PREFIX}${fingerprint}`;
    const modeKey = `${MODE_OVERRIDE_STORAGE_PREFIX}${fingerprint}`;

    const [dayOverride, setDayOverride] = useState<number | null>(null);
    const [modeOverride, setModeOverride] = useState<AnalysisMode | null>(null);
    const [savedFilterId, setSavedFilterId] = useState<UUID | null>(null);

    useEffect(() => {
        if (!open) {
            return;
        }

        const localDays = readStoredDays(dayKey);
        const localMode = readStoredMode(modeKey);
        let active = true;

        axios
            .get<{ data: SavedFilterSummary[] }>('/api/saved-filters')
            .then((response) => {
                if (!active) {
                    return;
                }
                const match =
                    response.data.data.find(
                        (saved) =>
                            filtersFingerprint(saved.filters) === fingerprint,
                    ) ?? null;
                setSavedFilterId(match?.id ?? null);
                setDayOverride(match?.analysis_days ?? localDays);
                setModeOverride(match?.analysis_mode ?? localMode);
            })
            .catch(() => {
                if (!active) {
                    return;
                }
                setSavedFilterId(null);
                setDayOverride(localDays);
                setModeOverride(localMode);
            });

        return () => {
            active = false;
        };
    }, [open, fingerprint, dayKey, modeKey]);

    const applyDays = useCallback(
        (value: number | null) => {
            setDayOverride(value);

            if (value === null) {
                localStorage.removeItem(dayKey);
            } else {
                localStorage.setItem(dayKey, String(value));
            }

            if (savedFilterId) {
                void axios.patch(
                    `/api/saved-filters/${savedFilterId}/analysis-days`,
                    { analysis_days: value },
                );
            }
        },
        [dayKey, savedFilterId],
    );

    const applyMode = useCallback(
        (value: AnalysisMode | null) => {
            setModeOverride(value);

            if (value === null) {
                localStorage.removeItem(modeKey);
            } else {
                localStorage.setItem(modeKey, value);
            }

            if (savedFilterId) {
                void axios.patch(
                    `/api/saved-filters/${savedFilterId}/analysis-mode`,
                    { analysis_mode: value },
                );
            }
        },
        [modeKey, savedFilterId],
    );

    return {
        effectiveDays: dayOverride ?? autoDays,
        isDaysOverridden: dayOverride !== null,
        modeOverride,
        isSaved: savedFilterId !== null,
        applyDays,
        applyMode,
    };
}

export function TransactionAnalysisDrawer({
    open,
    onOpenChange,
    filters,
}: TransactionAnalysisDrawerProps) {
    const locale = useLocale();
    const [data, setData] = useState<AnalysisData | null>(null);
    const [isLoading, setIsLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const loadAnalysis = useCallback(async () => {
        setIsLoading(true);
        setError(null);

        try {
            const query = buildQueryString(serializeFilters(filters));
            const response = await fetch(
                `/api/transactions/analysis?${query}`,
                {
                    headers: { Accept: 'application/json' },
                },
            );

            if (!response.ok) {
                throw new Error('Request failed');
            }

            setData((await response.json()) as AnalysisData);
        } catch {
            setError(__('Could not load the analysis. Please try again.'));
        } finally {
            setIsLoading(false);
        }
    }, [filters]);

    useEffect(() => {
        if (open) {
            void loadAnalysis();
        }
    }, [open, loadAnalysis]);

    const currency = data?.currency ?? '';
    const hasTransactions = (data?.summary.count ?? 0) > 0;
    const income = data?.summary.income ?? 0;
    const expense = data?.summary.expense ?? 0;
    const net = data?.summary.net ?? 0;

    const {
        effectiveDays,
        isDaysOverridden,
        modeOverride,
        isSaved,
        applyDays,
        applyMode,
    } = useAnalysisPreferences(open, filters, data?.summary.days ?? 0);

    const averagePerDay =
        effectiveDays > 0 ? Math.round(expense / effectiveDays) : expense;

    // The day override is the user telling us the real duration, so it decides
    // the automatic view too: a trip whose transactions posted over eight
    // months is still a trip, and stays on the bounded shape.
    const effectiveMode =
        modeOverride ?? detectMode(income, expense, effectiveDays);

    const monthlyPoints = useMemo(
        () => toMonthlyPoints(data?.over_time.points ?? [], locale),
        [data, locale],
    );

    const { resolvedMode, boundedMode, trendRates, showsIncome } = useMemo(
        () =>
            resolveAnalysisView(
                effectiveMode,
                income,
                expense,
                monthlyPoints,
                data?.summary.first_date ?? null,
            ),
        [effectiveMode, income, expense, monthlyPoints, data],
    );

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto w-full max-w-5xl overflow-y-auto p-6">
                    <DrawerHeader className="gap-0 px-0">
                        <div className="-mt-8 flex min-h-9 justify-end">
                            {hasTransactions && (
                                <ModeToggle
                                    override={modeOverride}
                                    resolvedMode={resolvedMode}
                                    isSaved={isSaved}
                                    onApply={applyMode}
                                />
                            )}
                        </div>
                        <div className="my-4 flex flex-col items-center gap-2 text-center">
                            <DrawerTitle className="text-xl">
                                {__('Analysis')}
                            </DrawerTitle>
                            <DrawerDescription className="text-sm text-pretty">
                                {__(
                                    'A breakdown of the transactions matching your current filters.',
                                )}
                            </DrawerDescription>
                        </div>
                    </DrawerHeader>

                    {isLoading && <AnalysisSkeleton />}

                    {!isLoading && error && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {error}
                        </p>
                    )}

                    {!isLoading && !error && !hasTransactions && (
                        <p className="py-12 text-center text-sm text-muted-foreground">
                            {__('No transactions match the current filters.')}
                        </p>
                    )}

                    {!isLoading && !error && data && hasTransactions && (
                        <div className="flex flex-col gap-6">
                            {trendRates ? (
                                <TrendCards
                                    rates={trendRates}
                                    months={monthlyPoints}
                                    count={data.summary.count}
                                    currency={currency}
                                    locale={locale}
                                    showsIncome={showsIncome}
                                />
                            ) : (
                                <SummaryCards
                                    mode={boundedMode}
                                    income={income}
                                    expense={expense}
                                    net={net}
                                    count={data.summary.count}
                                    currency={currency}
                                    days={effectiveDays}
                                    averagePerDay={averagePerDay}
                                    isDaysOverridden={isDaysOverridden}
                                    isSaved={isSaved}
                                    onApplyDays={applyDays}
                                />
                            )}

                            {trendRates ? (
                                <MonthlyTrendChart
                                    months={monthlyPoints}
                                    average={trendRates.average}
                                    currency={currency}
                                    locale={locale}
                                    showsIncome={showsIncome}
                                    firstDate={data.summary.first_date ?? null}
                                />
                            ) : (
                                <OverTimeChart
                                    points={data.over_time.points}
                                    currency={currency}
                                    locale={locale}
                                    mode={boundedMode}
                                />
                            )}

                            {!trendRates && (
                                <LargestTransactions
                                    items={data.largest_expenses ?? []}
                                    currency={currency}
                                    locale={locale}
                                    filters={filters}
                                />
                            )}

                            {data.distinct_category_count > 1 && (
                                <CategoryBreakdown
                                    slices={data.by_category}
                                    currency={currency}
                                />
                            )}

                            {data.distinct_payee_count > 1 && (
                                <PayeeBreakdown
                                    slices={data.by_payee}
                                    currency={currency}
                                    locale={locale}
                                />
                            )}

                            {data.distinct_account_count > 1 && (
                                <AccountBreakdown
                                    slices={data.by_account}
                                    currency={currency}
                                />
                            )}

                            {data.distinct_label_count > 1 && (
                                <TagBreakdown
                                    slices={data.by_tag}
                                    currency={currency}
                                />
                            )}
                        </div>
                    )}
                </div>
            </DrawerContent>
        </Drawer>
    );
}

/**
 * Wraps a widget in the same glowing-edge card the dashboard uses, so the
 * drawer reads as a set of distinct panels rather than one long scroll.
 */
function Panel({
    title,
    children,
    contentClassName,
}: {
    title?: string;
    children: ReactNode;
    contentClassName?: string;
}) {
    return (
        <Card className="w-full">
            {title && (
                <CardHeader>
                    <CardTitle>{title}</CardTitle>
                </CardHeader>
            )}
            <CardContent
                className={cn('flex flex-col gap-3', contentClassName)}
            >
                {children}
            </CardContent>
        </Card>
    );
}

function SummaryCards({
    mode,
    income,
    expense,
    net,
    count,
    currency,
    days,
    averagePerDay,
    isDaysOverridden,
    isSaved,
    onApplyDays,
}: {
    mode: BoundedMode;
    income: number;
    expense: number;
    net: number;
    count: number;
    currency: string;
    days: number;
    averagePerDay: number;
    isDaysOverridden: boolean;
    isSaved: boolean;
    onApplyDays: (value: number | null) => void;
}) {
    const margin = income > 0 ? Math.round((net / income) * 100) : 0;

    return (
        <Panel>
            {mode === 'income' ? (
                <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <SummaryCard
                        label={__('Income')}
                        amount={income}
                        currency={currency}
                        tone="income"
                    />
                    <SummaryCard
                        label={__('Expenses')}
                        amount={expense}
                        currency={currency}
                        tone="expense"
                    />
                    <SummaryCard
                        label={__('Net result')}
                        amount={net}
                        currency={currency}
                        tone={net >= 0 ? 'income' : 'expense'}
                    />
                    <div className="rounded-lg bg-muted/50 p-4">
                        <div className="flex h-6 items-center">
                            <p className="text-xs text-muted-foreground">
                                {__('Margin')}
                            </p>
                        </div>
                        <p
                            className={cn(
                                'mt-1 text-lg font-semibold tabular-nums',
                                net >= 0 ? 'text-emerald-600' : 'text-red-600',
                            )}
                        >
                            {margin}%
                        </p>
                    </div>
                </div>
            ) : (
                <div className="grid grid-cols-2 gap-3">
                    <SummaryCard
                        label={__('Total spent')}
                        amount={expense}
                        currency={currency}
                        tone="expense"
                    />
                    <div className="rounded-lg bg-muted/50 p-4">
                        <div className="flex h-6 items-center justify-between">
                            <p className="text-xs text-muted-foreground">
                                {__('Avg / day')}
                            </p>
                            <DayEditorPopover
                                days={days}
                                isOverridden={isDaysOverridden}
                                isSaved={isSaved}
                                onApply={onApplyDays}
                            />
                        </div>
                        <AmountDisplay
                            amountInCents={averagePerDay}
                            currencyCode={currency}
                            className="mt-1 text-lg font-semibold text-red-600 tabular-nums"
                        />
                    </div>
                </div>
            )}

            <p className="text-xs text-muted-foreground">
                {count} {__('transactions')} · {days} {__('days')}
                {isDaysOverridden &&
                    mode === 'expense' &&
                    ` (${__('adjusted')})`}
            </p>
        </Panel>
    );
}

function SummaryCard({
    label,
    amount,
    currency,
    tone,
    badge,
}: {
    label: string;
    amount: number;
    currency: string;
    tone: 'income' | 'expense';
    /** Sits beside the amount, for a card that qualifies its own figure. */
    badge?: ReactNode;
}) {
    return (
        <div className="rounded-lg bg-muted/50 p-4">
            <div className="flex h-6 items-center">
                <p className="text-xs text-muted-foreground">{label}</p>
            </div>
            <div className="mt-1 flex flex-wrap items-baseline gap-x-2">
                <AmountDisplay
                    amountInCents={amount}
                    currencyCode={currency}
                    className={cn(
                        'text-lg font-semibold tabular-nums',
                        tone === 'income' && 'text-emerald-600',
                        tone === 'expense' && 'text-red-600',
                    )}
                />
                {badge}
            </div>
        </div>
    );
}

/**
 * Renders the span as the months it covers, so the denominator behind a
 * monthly average is legible without counting days.
 */
function monthRange(months: MonthlyPoint[], locale: string): string {
    const first = months.at(0);
    const last = months.at(-1);

    if (!first || !last) {
        return '';
    }

    const label = (key: string) =>
        formatDate(parseISO(`${key}-01`), 'MMM yyyy', locale);

    return first.key === last.key
        ? label(first.key)
        : `${label(first.key)} – ${label(last.key)}`;
}

/**
 * The pair of numbers that answer "how am I doing" over an open-ended span:
 * the monthly rate, and where the recent months sit against it.
 */
function TrendCards({
    rates,
    months,
    count,
    currency,
    locale,
    showsIncome,
}: {
    rates: MonthlyRates;
    months: MonthlyPoint[];
    count: number;
    currency: string;
    locale: string;
    showsIncome: boolean;
}) {
    const change = rates.changePercentage;
    const isAdverse = isAdverseChange(change, showsIncome);
    const ChangeIcon =
        change === null || change === 0
            ? Minus
            : change > 0
              ? TrendingUp
              : TrendingDown;

    // Only a net figure can be good news; spending is always in the red.
    const tone = (amount: number): 'income' | 'expense' =>
        showsIncome && amount >= 0 ? 'income' : 'expense';

    return (
        <Panel>
            <div
                className={cn(
                    'grid gap-3',
                    rates.recentAverage !== null && 'grid-cols-2',
                )}
            >
                <SummaryCard
                    label={
                        showsIncome
                            ? __('Monthly net average')
                            : __('Monthly average')
                    }
                    amount={rates.average}
                    currency={currency}
                    tone={tone(rates.average)}
                    badge={
                        // Owns up to the denominator: part-months at either end
                        // of the span are left out of every figure here.
                        <span className="text-xs text-muted-foreground">
                            {__('over :count whole months', {
                                count: rates.months,
                            })}
                        </span>
                    }
                />

                {rates.recentAverage !== null && (
                    <SummaryCard
                        label={__('Last :count months', {
                            count: RECENT_MONTHS,
                        })}
                        amount={rates.recentAverage}
                        currency={currency}
                        tone={tone(rates.recentAverage)}
                        badge={
                            change !== null && (
                                <span className="flex items-center gap-1 text-xs">
                                    <span
                                        className={cn(
                                            'flex items-center gap-0.5 font-medium tabular-nums',
                                            change === 0 &&
                                                'text-muted-foreground',
                                            isAdverse && 'text-amber-500',
                                            change !== 0 &&
                                                !isAdverse &&
                                                'text-emerald-500',
                                        )}
                                    >
                                        <ChangeIcon className="size-3.5" />
                                        {change > 0 ? '+' : ''}
                                        {change}%
                                    </span>
                                    <span className="text-muted-foreground">
                                        {__('vs. average')}
                                    </span>
                                </span>
                            )
                        }
                    />
                )}
            </div>

            <p className="text-xs text-muted-foreground">
                {count} {__('transactions')} · {monthRange(months, locale)}
            </p>
        </Panel>
    );
}

function ModeToggle({
    override,
    resolvedMode,
    isSaved,
    onApply,
}: {
    override: AnalysisMode | null;
    resolvedMode: AnalysisMode;
    isSaved: boolean;
    onApply: (value: AnalysisMode | null) => void;
}) {
    // Picking a view that cannot be built would otherwise change nothing on
    // screen and say nothing about why.
    const unavailable = override === 'trend' && resolvedMode !== 'trend';
    const [open, setOpen] = useState(false);

    const options: { value: AnalysisMode | null; label: string }[] = [
        { value: null, label: __('Automatic') },
        { value: 'expense', label: modeLabel('expense') },
        { value: 'income', label: modeLabel('income') },
        { value: 'trend', label: modeLabel('trend') },
    ];

    // The trigger names what is on screen, not what was asked for: a forced
    // trend view that could not be built falls back, and so does the label.
    const triggerLabel = modeLabel(resolvedMode);

    const choose = (value: AnalysisMode | null) => {
        onApply(value);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button variant="outline" size="sm" className="gap-2">
                    <SlidersHorizontal className="h-3.5 w-3.5" />
                    <span className="hidden sm:inline">{triggerLabel}</span>
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-60" align="end">
                <div className="flex flex-col gap-1">
                    <p className="px-2 py-1 text-xs font-medium text-muted-foreground">
                        {__('Analysis view')}
                    </p>
                    {options.map((option) => {
                        const selected = override === option.value;

                        return (
                            <Button
                                key={option.label}
                                variant="ghost"
                                size="sm"
                                className="justify-between"
                                onClick={() => choose(option.value)}
                            >
                                {option.label}
                                {selected && <Check className="h-3.5 w-3.5" />}
                            </Button>
                        );
                    })}
                    {unavailable && (
                        <p className="px-2 pt-1 text-xs text-amber-600">
                            {__(
                                'Monthly trend needs at least one whole calendar month.',
                            )}
                        </p>
                    )}
                    {isSaved && (
                        <p className="px-2 pt-1 text-xs text-muted-foreground">
                            {__('Saved with this filter.')}
                        </p>
                    )}
                </div>
            </PopoverContent>
        </Popover>
    );
}

function DayEditorPopover({
    days,
    isOverridden,
    isSaved,
    onApply,
}: {
    days: number;
    isOverridden: boolean;
    isSaved: boolean;
    onApply: (value: number | null) => void;
}) {
    const [open, setOpen] = useState(false);
    const [value, setValue] = useState(String(days));

    useEffect(() => {
        if (open) {
            setValue(String(days));
        }
    }, [open, days]);

    const save = () => {
        const parsed = Number.parseInt(value, 10);
        if (Number.isFinite(parsed) && parsed > 0) {
            onApply(parsed);
            setOpen(false);
        }
    };

    const reset = () => {
        onApply(null);
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon"
                    className="h-6 w-6 text-muted-foreground"
                    aria-label={__('Adjust number of days')}
                >
                    <Settings2 className="h-3.5 w-3.5" />
                </Button>
            </PopoverTrigger>
            <PopoverContent className="w-72" align="end">
                <div className="flex flex-col gap-3">
                    <div className="flex flex-col gap-1">
                        <p className="text-sm font-medium">
                            {__('Days for daily average')}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {__(
                                'Override the date span when it does not match the real duration.',
                            )}
                        </p>
                    </div>
                    <Input
                        type="number"
                        min={1}
                        value={value}
                        onChange={(event) => setValue(event.target.value)}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                save();
                            }
                        }}
                    />
                    {isSaved && (
                        <p className="text-xs text-muted-foreground">
                            {__('Saved with this filter.')}
                        </p>
                    )}
                    <div className="flex justify-between gap-2">
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={reset}
                            disabled={!isOverridden}
                        >
                            {__('Reset to auto')}
                        </Button>
                        <Button size="sm" onClick={save}>
                            {__('Apply')}
                        </Button>
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

function OverTimeChart({
    points,
    currency,
    locale,
    mode,
}: {
    points: OverTimePoint[];
    currency: string;
    locale: string;
    mode: BoundedMode;
}) {
    const cumulativeKey =
        mode === 'income' ? 'cumulative_net' : 'cumulative_expense';
    const cumulativeLabel =
        mode === 'income' ? __('Cumulative net') : __('Cumulative spend');

    const config: ChartConfig = {
        income: { label: __('Income'), color: 'var(--color-chart-2)' },
        expense: { label: __('Expenses'), color: 'var(--color-chart-5)' },
        [cumulativeKey]: {
            label: cumulativeLabel,
            color: 'var(--color-chart-1)',
        },
    };

    return (
        <Panel title={__('Spending over time')}>
            <ChartContainer config={config} className="h-64 w-full">
                <ComposedChart data={points}>
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        minTickGap={16}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        width={48}
                        tickFormatter={(value) => compactAmount(value, locale)}
                    />
                    <Tooltip
                        content={
                            <OverTimeTooltip
                                currency={currency}
                                cumulativeKey={cumulativeKey}
                                cumulativeLabel={cumulativeLabel}
                                mode={mode}
                            />
                        }
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    />
                    {mode === 'income' && (
                        <Bar
                            dataKey="income"
                            fill="var(--color-chart-2)"
                            radius={[3, 3, 0, 0]}
                        />
                    )}
                    <Bar
                        dataKey="expense"
                        fill="var(--color-chart-5)"
                        radius={[3, 3, 0, 0]}
                    />
                    <Line
                        type="monotone"
                        dataKey={cumulativeKey}
                        stroke="var(--color-chart-1)"
                        strokeWidth={2}
                        dot={false}
                    />
                </ComposedChart>
            </ChartContainer>
        </Panel>
    );
}

interface TooltipPayloadItem {
    name?: string;
    dataKey?: string;
    value?: number;
    payload?: OverTimePoint;
}

function OverTimeTooltip({
    active,
    payload,
    currency,
    cumulativeKey,
    cumulativeLabel,
    mode,
}: {
    active?: boolean;
    payload?: TooltipPayloadItem[];
    currency: string;
    cumulativeKey: 'cumulative_expense' | 'cumulative_net';
    cumulativeLabel: string;
    mode: BoundedMode;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0]?.payload;
    const keys: {
        label: string;
        key: 'income' | 'expense' | 'cumulative_expense' | 'cumulative_net';
    }[] = [
        ...(mode === 'income'
            ? ([{ label: __('Income'), key: 'income' }] as const)
            : []),
        { label: __('Expenses'), key: 'expense' },
        { label: cumulativeLabel, key: cumulativeKey },
    ];

    return (
        <AmountRowsTooltip
            title={point?.label}
            currency={currency}
            rows={keys.map((row) => ({
                label: row.label,
                amount: point ? point[row.key] : 0,
            }))}
        />
    );
}

/**
 * The shared body of the chart tooltips: a title over labelled amounts, all
 * formatted the same way whichever chart raised it.
 */
function AmountRowsTooltip({
    title,
    rows,
    currency,
}: {
    title?: string;
    rows: { label: string; amount: number }[];
    currency: string;
}) {
    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{title}</div>
            {rows.map((row) => (
                <div
                    key={row.label}
                    className="mt-1 flex items-center justify-between gap-4"
                >
                    <span className="text-muted-foreground">{row.label}</span>
                    <AmountDisplay
                        amountInCents={row.amount}
                        currencyCode={currency}
                        className="font-mono tabular-nums"
                    />
                </div>
            ))}
        </div>
    );
}

/**
 * A sentinel that keeps an absent category/account from colliding with a real
 * one named with an empty string when counting distinct values.
 */
const MISSING = '\0';

/**
 * Monthly bars without a cumulative line: over an open-ended span the running
 * total only ever climbs, while the month-to-month shape is the whole story.
 */
function MonthlyTrendChart({
    months,
    average,
    currency,
    locale,
    showsIncome,
    firstDate,
}: {
    months: MonthlyPoint[];
    average: number;
    currency: string;
    locale: string;
    showsIncome: boolean;
    firstDate: string | null;
}) {
    const config: ChartConfig = {
        expense: { label: __('Expenses'), color: 'var(--color-chart-5)' },
        ...(showsIncome
            ? { income: { label: __('Income'), color: 'var(--color-chart-2)' } }
            : {}),
    };

    return (
        <Panel
            title={
                showsIncome
                    ? __('Income & expenses per month')
                    : __('Spending per month')
            }
        >
            <ChartContainer config={config} className="h-64 w-full">
                <ComposedChart data={months}>
                    <XAxis
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        tickMargin={8}
                        minTickGap={16}
                    />
                    <YAxis
                        tickLine={false}
                        axisLine={false}
                        width={48}
                        tickFormatter={(value) => compactAmount(value, locale)}
                    />
                    <Tooltip
                        content={
                            <MonthlyTrendTooltip
                                currency={currency}
                                showsIncome={showsIncome}
                            />
                        }
                        cursor={{ fill: 'var(--color-muted)', opacity: 0.3 }}
                    />
                    {/*
                     * The rate the cards report, so the recent bars can be read
                     * against it. Drawn for spending only: with income in play
                     * the cards report a net figure, and no line over
                     * income-and-expense bars would sit on it meaningfully.
                     */}
                    {!showsIncome && (
                        <ReferenceLine
                            y={average}
                            stroke="var(--color-muted-foreground)"
                            strokeDasharray="4 4"
                        />
                    )}
                    {showsIncome && (
                        <Bar
                            dataKey="income"
                            fill="var(--color-chart-2)"
                            radius={[3, 3, 0, 0]}
                        />
                    )}
                    {/*
                     * A part-month bar is faded rather than dropped: it is real
                     * spending, but at full strength it reads as a fall against
                     * the average line when the month simply has not finished.
                     */}
                    <Bar
                        dataKey="expense"
                        fill="var(--color-chart-5)"
                        radius={[3, 3, 0, 0]}
                    >
                        {months.map((month) => (
                            <Cell
                                key={month.key}
                                fill="var(--color-chart-5)"
                                fillOpacity={
                                    isPartialMonth(month.key, firstDate)
                                        ? 0.35
                                        : 1
                                }
                            />
                        ))}
                    </Bar>
                </ComposedChart>
            </ChartContainer>
        </Panel>
    );
}

function MonthlyTrendTooltip({
    active,
    payload,
    currency,
    showsIncome,
}: {
    active?: boolean;
    payload?: { payload?: MonthlyPoint }[];
    currency: string;
    showsIncome: boolean;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const month = payload[0]?.payload;
    const keys: { label: string; key: 'income' | 'expense' | 'net' }[] =
        showsIncome
            ? [
                  { label: __('Income'), key: 'income' },
                  { label: __('Expenses'), key: 'expense' },
                  { label: __('Net result'), key: 'net' },
              ]
            : [{ label: __('Expenses'), key: 'expense' }];

    return (
        <AmountRowsTooltip
            title={month?.label}
            currency={currency}
            rows={keys.map((row) => ({
                label: row.label,
                amount: month ? month[row.key] : 0,
            }))}
        />
    );
}

function LargestTransactions({
    items,
    currency,
    locale,
    filters,
}: {
    items: LargestExpense[];
    currency: string;
    locale: string;
    filters: TransactionFilters;
}) {
    const [expanded, setExpanded] = useState(false);

    if (items.length === 0) {
        return null;
    }

    const visible = expanded ? items : items.slice(0, 5);

    // Drop a column whose value is identical across every row (so the filter
    // has already pinned it, or the set just happens to share it) — it carries
    // no information. Labels are filter-driven: filtering to a single label
    // makes that column redundant even when rows carry extra labels.
    const showCategory =
        new Set(items.map((item) => item.category?.name ?? MISSING)).size > 1;
    const showAccount =
        new Set(items.map((item) => item.account?.name ?? MISSING)).size > 1;
    const showLabels =
        filters.labelIds.length !== 1 &&
        items.some((item) => item.labels.length > 0);

    return (
        <Panel title={__('Largest expenses')}>
            <div className="overflow-x-auto">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="text-left text-xs text-muted-foreground">
                            <th className="py-1.5 pr-3 font-medium">
                                {__('Date')}
                            </th>
                            {showCategory && (
                                <th className="py-1.5 pr-3 font-medium">
                                    {__('Category')}
                                </th>
                            )}
                            {showAccount && (
                                <th className="py-1.5 pr-3 font-medium">
                                    {__('Account')}
                                </th>
                            )}
                            <th className="py-1.5 pr-3 font-medium">
                                {__('Description')}
                            </th>
                            {showLabels && (
                                <th className="py-1.5 pr-3 font-medium">
                                    {__('Labels')}
                                </th>
                            )}
                            <th className="py-1.5 text-right font-medium">
                                {__('Amount')}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((item) => (
                            <tr
                                key={item.id}
                                className="border-t border-border/60"
                            >
                                <td className="py-2 pr-3 whitespace-nowrap text-muted-foreground">
                                    {formatDate(
                                        parseISO(item.date),
                                        'MMM d, yy',
                                        locale,
                                    )}
                                </td>
                                {showCategory && (
                                    <td className="max-w-[160px] py-2 pr-3">
                                        <CategoryChip
                                            category={item.category}
                                        />
                                    </td>
                                )}
                                {showAccount && (
                                    <td className="py-2 pr-3">
                                        {item.account ? (
                                            <div className="flex items-center gap-2">
                                                <BankLogo
                                                    src={
                                                        item.account.bank?.logo
                                                    }
                                                    name={
                                                        item.account.bank?.name
                                                    }
                                                    className="h-4 w-4"
                                                />
                                                <AccountName
                                                    account={{
                                                        name: item.account.name,
                                                        name_iv: null,
                                                        encrypted: false,
                                                    }}
                                                    className="truncate"
                                                />
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                )}
                                <td className="max-w-[160px] truncate py-2 pr-3">
                                    {item.description || (
                                        <span className="text-muted-foreground">
                                            —
                                        </span>
                                    )}
                                </td>
                                {showLabels && (
                                    <td className="py-2 pr-3">
                                        <LabelBadges
                                            labels={
                                                item.labels as unknown as Label[]
                                            }
                                            max={2}
                                        />
                                    </td>
                                )}
                                <td className="py-2 text-right whitespace-nowrap">
                                    <AmountDisplay
                                        amountInCents={-item.amount}
                                        currencyCode={currency}
                                        className="font-mono text-red-600 tabular-nums"
                                    />
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {items.length > 5 && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="self-start text-muted-foreground"
                    onClick={() => setExpanded((previous) => !previous)}
                >
                    {expanded ? __('Show less') : __('Show more')}
                </Button>
            )}
        </Panel>
    );
}

function CategoryChip({ category }: { category: LargestExpense['category'] }) {
    if (!category) {
        return <span className="text-muted-foreground">—</span>;
    }

    const classes = getCategoryColorClasses(
        (category.color ?? 'gray') as CategoryColor,
    );
    const Icon = (Icons[(category.icon ?? 'HelpCircle') as CategoryIcon] ??
        HelpCircle) as LucideIcon;

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center gap-1.5 rounded-md px-1.5 py-0.5 text-xs',
                classes.bg,
                classes.text,
            )}
        >
            <Icon className="size-3 shrink-0" />
            <span className="truncate">{category.name}</span>
        </span>
    );
}

/**
 * Builds the icon-in-a-coloured-circle marker the dashboard uses for a
 * category, reused for every category row in the drawer's breakdowns.
 */
function categoryLeading(color: string | null, icon: string | null): ReactNode {
    const classes = getCategoryColorClasses((color ?? 'gray') as CategoryColor);
    const Icon = (Icons[(icon ?? 'HelpCircle') as CategoryIcon] ??
        HelpCircle) as LucideIcon;

    return (
        <div
            className={cn(
                'flex size-6 shrink-0 items-center justify-center rounded-full',
                classes.bg,
                classes.text,
            )}
        >
            <Icon className="size-4" />
        </div>
    );
}

function CategoryBreakdown({
    slices,
    currency,
}: {
    slices: CategorySlice[];
    currency: string;
}) {
    const { categoryBarColor } = useChartColors();
    const total = slices.reduce((sum, slice) => sum + slice.amount, 0);

    const childrenById = useMemo(() => {
        const map: Record<string, CategorySlice[]> = {};
        for (const slice of slices) {
            if (slice.category_id) {
                map[slice.category_id] = slice.children ?? [];
            }
        }
        return map;
    }, [slices]);

    const expandable = useExpandableCategories<CategorySlice>(
        async (categoryId) => childrenById[categoryId] ?? [],
        slices,
    );

    const adapter: CategoryBreakdownAdapter<CategorySlice> = {
        getId: (item) => item.category_id ?? '',
        getKey: (item, index) => item.category_id ?? `category-${index}`,
        getName: (item) => item.name,
        getAmount: (item) => item.amount,
        getPercentage: (item) => (total > 0 ? (item.amount / total) * 100 : 0),
        getBarColor: (item, index) =>
            categoryBarColor((item.color ?? 'gray') as CategoryColor, index),
        renderLeading: (item) => categoryLeading(item.color, item.icon),
        canExpand: (item) => (item.children?.length ?? 0) > 0,
    };

    return (
        <Panel title={__('Spending by category')}>
            <div className="flex flex-col gap-3">
                {slices.map((slice, index) => (
                    <CategoryBreakdownRow
                        key={slice.category_id ?? `category-${index}`}
                        item={slice}
                        index={index}
                        currencyCode={currency}
                        adapter={adapter}
                        expandable={expandable}
                        expandColumn
                    />
                ))}
            </div>
        </Panel>
    );
}

function HorizontalBarBreakdown({
    title,
    data,
    currency,
    locale,
    color,
}: {
    title: string;
    data: { name: string; amount: number }[];
    currency: string;
    locale: string;
    color: string;
}) {
    const config: ChartConfig = {
        amount: { label: __('Spent'), color },
    };

    return (
        <Panel title={title}>
            <ChartContainer
                config={config}
                className="w-full"
                style={{ height: `${Math.max(data.length * 44, 88)}px` }}
            >
                <ResponsiveContainer>
                    <ComposedChart
                        layout="vertical"
                        data={data}
                        margin={{ left: 8, right: 16 }}
                    >
                        <XAxis
                            type="number"
                            hide
                            tickFormatter={(value) =>
                                compactAmount(value, locale)
                            }
                        />
                        <YAxis
                            type="category"
                            dataKey="name"
                            tickLine={false}
                            axisLine={false}
                            width={96}
                        />
                        <Tooltip
                            cursor={{
                                fill: 'var(--color-muted)',
                                opacity: 0.3,
                            }}
                            content={<NamedAmountTooltip currency={currency} />}
                        />
                        <Bar
                            dataKey="amount"
                            fill={color}
                            radius={[0, 3, 3, 0]}
                        />
                    </ComposedChart>
                </ResponsiveContainer>
            </ChartContainer>
        </Panel>
    );
}

function TagBreakdown({
    slices,
    currency,
}: {
    slices: TagSlice[];
    currency: string;
}) {
    const { categoryBarColor } = useChartColors();
    const total = slices.reduce((sum, slice) => sum + slice.amount, 0);

    const adapter: CategoryBreakdownAdapter<TagSlice> = {
        getId: (item) => item.id,
        getKey: (item, index) => item.id ?? `tag-${index}`,
        getName: (item) => item.name,
        getAmount: (item) => item.amount,
        getPercentage: (item) => (total > 0 ? (item.amount / total) * 100 : 0),
        getBarColor: (item, index) =>
            categoryBarColor((item.color ?? 'gray') as CategoryColor, index),
        renderLeading: (item, index) => (
            <span
                className="size-3 shrink-0 rounded-full"
                style={{
                    backgroundColor: categoryBarColor(
                        (item.color ?? 'gray') as CategoryColor,
                        index,
                    ),
                }}
            />
        ),
    };

    return (
        <Panel title={__('Spending by tag')}>
            <div className="flex flex-col gap-3">
                {slices.map((slice, index) => (
                    <CategoryBreakdownRow
                        key={slice.id ?? `tag-${index}`}
                        item={slice}
                        index={index}
                        currencyCode={currency}
                        adapter={adapter}
                    />
                ))}
            </div>
        </Panel>
    );
}

function PayeeBreakdown({
    slices,
    currency,
    locale,
}: {
    slices: PayeeSlice[];
    currency: string;
    locale: string;
}) {
    return (
        <HorizontalBarBreakdown
            title={__('Spending by payee')}
            data={slices.slice(0, 8)}
            currency={currency}
            locale={locale}
            color="var(--color-chart-3)"
        />
    );
}

const ACCOUNT_BAR_COLORS = [
    'var(--color-chart-1)',
    'var(--color-chart-2)',
    'var(--color-chart-3)',
    'var(--color-chart-4)',
    'var(--color-chart-5)',
    'var(--color-chart-6)',
    'var(--color-chart-7)',
    'var(--color-chart-8)',
];

function AccountBreakdown({
    slices,
    currency,
}: {
    slices: AccountSlice[];
    currency: string;
}) {
    const total = slices.reduce((sum, slice) => sum + slice.amount, 0);

    const adapter: CategoryBreakdownAdapter<AccountSlice> = {
        getId: (item) => item.id ?? '',
        getKey: (item, index) => item.id ?? `account-${index}`,
        getName: (item) => item.name,
        getAmount: (item) => item.amount,
        getPercentage: (item) => (total > 0 ? (item.amount / total) * 100 : 0),
        getBarColor: (_item, index) =>
            ACCOUNT_BAR_COLORS[index % ACCOUNT_BAR_COLORS.length],
        renderLeading: (item) => (
            <BankLogo
                src={item.bank?.logo}
                name={item.bank?.name}
                className="size-6 shrink-0"
                fallback="icon"
            />
        ),
    };

    return (
        <Panel title={__('Spending by account')}>
            <div className="flex flex-col gap-3">
                {slices.map((slice, index) => (
                    <CategoryBreakdownRow
                        key={slice.id ?? `account-${index}`}
                        item={slice}
                        index={index}
                        currencyCode={currency}
                        adapter={adapter}
                    />
                ))}
            </div>
        </Panel>
    );
}

function NamedAmountTooltip({
    active,
    payload,
    currency,
}: {
    active?: boolean;
    payload?: { payload?: { name: string; amount: number } }[];
    currency: string;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const slice = payload[0]?.payload;

    return (
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{slice?.name}</div>
            <AmountDisplay
                amountInCents={slice?.amount ?? 0}
                currencyCode={currency}
                className="mt-1 font-mono tabular-nums"
            />
        </div>
    );
}

function AnalysisSkeleton() {
    return (
        <div className="flex animate-pulse flex-col gap-8">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
                {Array.from({ length: 4 }).map((_, index) => (
                    <div
                        key={index}
                        className="h-20 rounded-lg border bg-muted/50"
                    />
                ))}
            </div>
            <div className="h-64 rounded-lg border bg-muted/50" />
            <div className="h-52 rounded-lg border bg-muted/50" />
        </div>
    );
}

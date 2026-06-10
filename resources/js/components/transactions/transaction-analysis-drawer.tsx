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
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { parseISO } from 'date-fns';
import * as Icons from 'lucide-react';
import {
    Check,
    HelpCircle,
    Settings2,
    SlidersHorizontal,
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
    ComposedChart,
    Line,
    ResponsiveContainer,
    Tooltip,
    XAxis,
    YAxis,
} from 'recharts';

type AnalysisMode = 'expense' | 'income';

/**
 * Income only changes the analysis into its income-and-expense shape once it
 * is a meaningful share of the spending; a stray refund should not flip a trip
 * into a profit-and-loss view.
 */
const INCOME_MODE_THRESHOLD = 0.15;

function detectMode(income: number, expense: number): AnalysisMode {
    return income > 0 && income >= expense * INCOME_MODE_THRESHOLD
        ? 'income'
        : 'expense';
}

interface AnalysisSummary {
    income: number;
    expense: number;
    net: number;
    count: number;
    days: number;
    average_expense_per_day: number;
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

function readStoredMode(key: string): AnalysisMode | null {
    const raw = localStorage.getItem(key);
    return raw === 'expense' || raw === 'income' ? raw : null;
}

/**
 * Resolves the day span and view mode used for a filter set.
 *
 * Both follow the same rule: an automatic value (the transaction span; the
 * income-share detection) unless the user overrides it. Overrides are
 * remembered per filter fingerprint in the browser and, when the current
 * filters match a saved filter, synced to the backend. A single lookup of the
 * saved filters backs both, so the drawer hits the API once per open.
 */
function useAnalysisPreferences(
    open: boolean,
    filters: TransactionFilters,
    autoDays: number,
    autoMode: AnalysisMode,
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
        effectiveMode: modeOverride ?? autoMode,
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
    const autoMode = detectMode(income, expense);

    const {
        effectiveDays,
        isDaysOverridden,
        effectiveMode,
        modeOverride,
        isSaved,
        applyDays,
        applyMode,
    } = useAnalysisPreferences(
        open,
        filters,
        data?.summary.days ?? 0,
        autoMode,
    );

    const averagePerDay =
        effectiveDays > 0 ? Math.round(expense / effectiveDays) : expense;

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]">
                <div className="mx-auto w-full max-w-5xl overflow-y-auto p-6">
                    <DrawerHeader className="gap-0 px-0">
                        <div className="-mt-8 flex min-h-9 justify-end">
                            {hasTransactions && (
                                <ModeToggle
                                    override={modeOverride}
                                    effectiveMode={effectiveMode}
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
                            <SummaryCards
                                mode={effectiveMode}
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

                            <OverTimeChart
                                points={data.over_time.points}
                                currency={currency}
                                locale={locale}
                                mode={effectiveMode}
                            />

                            <LargestTransactions
                                items={data.largest_expenses ?? []}
                                currency={currency}
                                locale={locale}
                                filters={filters}
                            />

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
    mode: AnalysisMode;
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
}: {
    label: string;
    amount: number;
    currency: string;
    tone: 'income' | 'expense';
}) {
    return (
        <div className="rounded-lg bg-muted/50 p-4">
            <div className="flex h-6 items-center">
                <p className="text-xs text-muted-foreground">{label}</p>
            </div>
            <AmountDisplay
                amountInCents={amount}
                currencyCode={currency}
                className={cn(
                    'mt-1 text-lg font-semibold tabular-nums',
                    tone === 'income' && 'text-emerald-600',
                    tone === 'expense' && 'text-red-600',
                )}
            />
        </div>
    );
}

function ModeToggle({
    override,
    effectiveMode,
    isSaved,
    onApply,
}: {
    override: AnalysisMode | null;
    effectiveMode: AnalysisMode;
    isSaved: boolean;
    onApply: (value: AnalysisMode | null) => void;
}) {
    const [open, setOpen] = useState(false);

    const options: { value: AnalysisMode | null; label: string }[] = [
        { value: null, label: __('Automatic') },
        { value: 'expense', label: __('Expenses only') },
        { value: 'income', label: __('Income & expenses') },
    ];

    const triggerLabel =
        override === null
            ? effectiveMode === 'income'
                ? __('Income & expenses')
                : __('Expenses only')
            : override === 'income'
              ? __('Income & expenses')
              : __('Expenses only');

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
    mode: AnalysisMode;
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

    const compact = (value: number) =>
        new Intl.NumberFormat(locale, {
            notation: 'compact',
            compactDisplay: 'short',
        }).format(value / 100);

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
                        tickFormatter={compact}
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
    mode: AnalysisMode;
}) {
    if (!active || !payload?.length) {
        return null;
    }

    const point = payload[0]?.payload;
    const rows: {
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
        <div className="rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            <div className="font-medium">{point?.label}</div>
            {rows.map((row) => (
                <div
                    key={row.key}
                    className="mt-1 flex items-center justify-between gap-4"
                >
                    <span className="text-muted-foreground">{row.label}</span>
                    <AmountDisplay
                        amountInCents={point ? point[row.key] : 0}
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
const MISSING = ' ';

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

    const compact = (value: number) =>
        new Intl.NumberFormat(locale, {
            notation: 'compact',
            compactDisplay: 'short',
        }).format(value / 100);

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
                        <XAxis type="number" hide tickFormatter={compact} />
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

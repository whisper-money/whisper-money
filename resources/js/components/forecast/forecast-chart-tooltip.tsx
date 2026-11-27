import { AmountDisplay } from '@/components/ui/amount-display';
import { ChartTooltipPortal } from '@/components/ui/chart';
import { useLocale } from '@/hooks/use-locale';
import { chartRoleColors } from '@/lib/chart-colors';
import { type ForecastChartPoint } from '@/lib/forecast/chart-series';
import {
    chartTooltipData,
    type ChartTooltipData,
    type ChartTooltipKind,
} from '@/lib/forecast/chart-tooltip';
import { balanceTone, isShort, monthWithYear } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastAllData, type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';

interface TooltipColors {
    income: string;
    expenses: string;
    close: string;
}

/** The swatches of the tooltip: the chart's income and expense bars and its closing line. */
export const FORECAST_TOOLTIP_COLORS: TooltipColors = {
    income: chartRoleColors.cashflowIncomeColor,
    expenses: chartRoleColors.cashflowExpenseColor,
    close: chartRoleColors.accountMainLineColor,
};

interface ForecastChartTooltipProps {
    active?: boolean;
    label?: string | number;
    coordinate?: { x?: number; y?: number };
    points: ForecastChartPoint[];
    forecast: ForecastData | ForecastAllData;
    colors?: TooltipColors;
}

function kindLabel(kind: ChartTooltipKind): string {
    switch (kind) {
        case 'actual':
            return __('Actual');
        case 'today':
            return __('So far + forecast');
        case 'forecast':
            return __('Forecast');
    }
}

function Row({
    marker,
    label,
    children,
    labelClassName,
}: {
    marker?: ReactNode;
    label: string;
    children: ReactNode;
    labelClassName?: string;
}) {
    return (
        <div className="flex min-w-0 items-center justify-between gap-4 leading-none">
            <div className="flex min-w-0 items-center gap-2">
                {marker}
                <span
                    className={cn(
                        'truncate text-muted-foreground',
                        labelClassName,
                    )}
                >
                    {label}
                </span>
            </div>
            <span className="shrink-0 font-mono font-medium whitespace-nowrap text-foreground tabular-nums">
                {children}
            </span>
        </div>
    );
}

function Swatch({ color, line = false }: { color: string; line?: boolean }) {
    return (
        <span
            className={cn(
                'shrink-0',
                line ? 'h-0.5 w-2.5' : 'size-2.5 rounded-xs',
            )}
            style={{ backgroundColor: color }}
        />
    );
}

/**
 * The hover card of the forecast chart, in the net-worth tooltip's look,
 * floating by the pointer.
 */
export function ForecastChartTooltip({
    active,
    label,
    coordinate,
    points,
    forecast,
    colors = FORECAST_TOOLTIP_COLORS,
}: ForecastChartTooltipProps) {
    const point = points.find((candidate) => candidate.month === label);
    const data = point ? chartTooltipData(point, forecast) : null;

    if (!active || data === null) {
        return null;
    }

    return (
        <ChartTooltipPortal coordinate={coordinate}>
            <ForecastChartTooltipCard
                data={data}
                forecast={forecast}
                colors={colors}
            />
        </ChartTooltipPortal>
    );
}

interface ForecastChartTooltipCardProps {
    data: ChartTooltipData;
    forecast: ForecastData | ForecastAllData;
    colors?: TooltipColors;
    id?: string;
    className?: string;
}

/**
 * The card itself: the month and where its figures come from, its income
 * and expenses, the closing balance with its likely range once it is a
 * forecast, and the lowest balance or, in the "All accounts" view, the
 * accounts that run short. The phone's chart places it over a tapped bar.
 */
export function ForecastChartTooltipCard({
    data,
    forecast,
    colors = FORECAST_TOOLTIP_COLORS,
    id,
    className,
}: ForecastChartTooltipCardProps) {
    const locale = useLocale();
    const currency = forecast.currencyCode;
    const total = forecast.scope === 'all';
    const threshold = forecast.account?.alertThreshold ?? null;

    return (
        <div
            id={id}
            className={cn(
                'grid max-w-[min(26rem,calc(100vw-2rem))] min-w-[12rem] grid-cols-[minmax(0,1fr)] items-start gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl',
                className,
            )}
            data-test="forecast-chart-tooltip"
        >
            <div className="flex items-baseline justify-between gap-4">
                <span className="font-medium first-letter:uppercase">
                    {monthWithYear(data.month, locale)}
                </span>
                <span className="text-muted-foreground">
                    {kindLabel(data.kind)}
                </span>
            </div>
            <div className="grid grid-cols-[minmax(0,1fr)] gap-1.5">
                <Row
                    marker={<Swatch color={colors.income} />}
                    label={__('Income')}
                >
                    <AmountDisplay
                        amountInCents={data.income}
                        currencyCode={currency}
                        showSign
                        className="text-success"
                    />
                </Row>
                <Row
                    marker={<Swatch color={colors.expenses} />}
                    label={__('Expenses')}
                >
                    <AmountDisplay
                        amountInCents={data.expenses === 0 ? 0 : -data.expenses}
                        currencyCode={currency}
                    />
                </Row>
            </div>
            {data.close !== null && (
                <div className="flex flex-col gap-1.5 border-t border-border/50 pt-1.5">
                    <Row
                        marker={<Swatch color={colors.close} line />}
                        label={
                            total ? __('Total balance') : __('Closing balance')
                        }
                        labelClassName="font-medium"
                    >
                        <AmountDisplay
                            amountInCents={data.close}
                            currencyCode={currency}
                        />
                    </Row>
                    {data.range && (
                        <Row
                            marker={<span className="size-2.5 shrink-0" />}
                            label={__('Likely range')}
                        >
                            <span className="text-muted-foreground">
                                <AmountDisplay
                                    amountInCents={data.range[0]}
                                    currencyCode={currency}
                                />
                                {' – '}
                                <AmountDisplay
                                    amountInCents={data.range[1]}
                                    currencyCode={currency}
                                />
                            </span>
                        </Row>
                    )}
                    {data.min !== null && (
                        <Row
                            marker={<span className="size-2.5 shrink-0" />}
                            label={__('Lowest balance')}
                        >
                            <AmountDisplay
                                amountInCents={data.min}
                                currencyCode={currency}
                                className={
                                    isShort(data.min, threshold)
                                        ? balanceTone(data.min, threshold)
                                        : undefined
                                }
                            />
                        </Row>
                    )}
                </div>
            )}
            {data.shortAccounts.length > 0 && (
                <div className="flex flex-col gap-1.5 border-t border-border/50 pt-1.5">
                    <span className="flex items-center gap-2 font-medium text-destructive">
                        <span className="size-2 shrink-0 rounded-full bg-destructive" />
                        {__('Accounts with a warning')}
                    </span>
                    {data.shortAccounts.map(({ account, min }) => (
                        <Row
                            key={account.id}
                            marker={<span className="size-2 shrink-0" />}
                            label={account.name}
                        >
                            <AmountDisplay
                                amountInCents={min}
                                currencyCode={account.currencyCode}
                                className={balanceTone(
                                    min,
                                    account.alertThreshold,
                                )}
                            />
                        </Row>
                    ))}
                </div>
            )}
        </div>
    );
}

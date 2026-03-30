import * as React from 'react';
import * as RechartsPrimitive from 'recharts';

import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';

const THEMES = { light: '', dark: '.dark' } as const;

export type ChartConfig = {
    [k in string]: {
        label?: React.ReactNode;
        icon?: React.ComponentType;
        color?: string;
    };
};

type ChartContextProps = {
    config: ChartConfig;
};

const ChartContext = React.createContext<ChartContextProps | null>(null);

function useChart() {
    const context = React.useContext(ChartContext);

    if (!context) {
        throw new Error('useChart must be used within a <ChartContainer />');
    }

    return context;
}

const ChartContainer = React.forwardRef<
    HTMLDivElement,
    React.ComponentProps<'div'> & {
        config: ChartConfig;
        children: React.ComponentProps<
            typeof RechartsPrimitive.ResponsiveContainer
        >['children'];
    }
>(({ id, className, children, config, ...props }, ref) => {
    const uniqueId = React.useId();
    const chartId = `chart-${id || uniqueId.replace(/:/g, '')}`;

    return (
        <ChartContext.Provider value={{ config }}>
            <div
                data-chart={chartId}
                ref={ref}
                className={cn(
                    "flex aspect-video justify-center text-xs min-w-0 overflow-hidden [&_.recharts-cartesian-axis-tick_text]:fill-muted-foreground [&_.recharts-cartesian-grid_line[stroke='#ccc']]:stroke-border/50 [&_.recharts-curve.recharts-tooltip-cursor]:stroke-border [&_.recharts-dot[stroke='#fff']]:stroke-transparent [&_.recharts-layer]:outline-none [&_.recharts-polar-grid_[stroke='#ccc']]:stroke-border [&_.recharts-radial-bar-background-sector]:fill-muted [&_.recharts-rectangle.recharts-tooltip-cursor]:fill-muted [&_.recharts-reference-line_[stroke='#ccc']]:stroke-border [&_.recharts-sector[stroke='#fff']]:stroke-transparent [&_.recharts-sector]:outline-none [&_.recharts-surface]:outline-none",
                    className,
                )}
                {...props}
            >
                <ChartStyle id={chartId} config={config} />
                <RechartsPrimitive.ResponsiveContainer
                    initialDimension={{ width: 1, height: 1 }}
                >
                    {children}
                </RechartsPrimitive.ResponsiveContainer>
            </div>
        </ChartContext.Provider>
    );
});
ChartContainer.displayName = 'Chart';

const ChartStyle = ({ id, config }: { id: string; config: ChartConfig }) => {
    const colorConfig = Object.entries(config).filter(
        ([, itemConfig]) => itemConfig.color,
    );

    if (!colorConfig.length) {
        return null;
    }

    return (
        <style
            dangerouslySetInnerHTML={{
                __html: Object.entries(THEMES)
                    .map(
                        ([, prefix]) => `
${prefix} [data-chart=${id}] {
${colorConfig
                                .map(([key, itemConfig]) => {
                                    return itemConfig.color
                                        ? `  --color-${key}: ${itemConfig.color};`
                                        : null;
                                })
                                .filter(Boolean)
                                .join('\n')}
}
`,
                    )
                    .join('\n'),
            }}
        />
    );
};

const ChartTooltip = RechartsPrimitive.Tooltip;

interface TooltipPayloadItem {
    dataKey?: string | number;
    name?: string;
    value?: number | string;
    color?: string;
    payload?: Record<string, unknown>;
}

interface ChartTooltipContentProps {
    active?: boolean;
    payload?: TooltipPayloadItem[];
    className?: string;
    indicator?: 'line' | 'dot' | 'dashed';
    hideLabel?: boolean;
    label?: string;
    labelFormatter?: (
        value: unknown,
        payload: TooltipPayloadItem[],
    ) => React.ReactNode;
    labelClassName?: string;
    formatter?: (
        value: unknown,
        name: string,
        item: TooltipPayloadItem,
        index: number,
        payload: Record<string, unknown>,
    ) => React.ReactNode;
    nameKey?: string;
    labelKey?: string;
    valueFormatter?: (value: number, accountId?: string) => React.ReactNode;
    accountCurrencies?: Record<string, string>;
    displayCurrency?: string;
    /** When set, tooltip shows liability rows and net-worth total instead of simple sum. */
    netWorthMode?: {
        liabilityTypeLabel: string;
        liabilityDotColor?: string;
    };
}

function formatCurrencyWithCode(value: number, currencyCode: string, locale: string): string {
    return formatCurrency(value, currencyCode, locale, 0, 0);
}

const ChartTooltipContent = React.forwardRef<
    HTMLDivElement,
    ChartTooltipContentProps
>(
    (
        {
            active,
            payload,
            className,
            indicator = 'dot',
            hideLabel = false,
            label,
            labelFormatter,
            labelClassName,
            formatter,
            nameKey,
            labelKey,
            valueFormatter,
            accountCurrencies,
            displayCurrency,
            netWorthMode,
        },
        ref,
    ) => {
        const { config } = useChart();
        const locale = useLocale();
        const { isPrivacyModeEnabled } = usePrivacyMode();

        const tooltipLabel = React.useMemo(() => {
            if (hideLabel || !payload?.length) {
                return null;
            }

            const [item] = payload;
            const key = `${labelKey || item?.dataKey || item?.name || 'value'}`;
            const itemConfig = getPayloadConfigFromPayload(config, item, key);
            const value =
                !labelKey && typeof label === 'string'
                    ? config[label as keyof typeof config]?.label || label
                    : itemConfig?.label;

            if (labelFormatter) {
                return (
                    <div className={cn('font-medium', labelClassName)}>
                        {labelFormatter(value, payload)}
                    </div>
                );
            }

            if (!value) {
                return null;
            }

            return (
                <div className={cn('font-medium', labelClassName)}>{value}</div>
            );
        }, [
            label,
            labelFormatter,
            payload,
            hideLabel,
            labelClassName,
            config,
            labelKey,
        ]);

        const currencyTotals = React.useMemo(() => {
            if (!payload?.length) {
                return null;
            }

            // In net worth mode, use pre-computed net worth from data point
            if (netWorthMode && displayCurrency) {
                const netWorth = payload[0]?.payload?.__net_worth as number | undefined;
                if (netWorth !== undefined) {
                    return [[displayCurrency, netWorth] as [string, number]];
                }
            }

            // When displayCurrency is set, all values are in a single currency
            if (displayCurrency) {
                const total = payload.reduce((sum, item) => {
                    return sum + (typeof item.value === 'number' ? item.value : 0);
                }, 0);
                return [[displayCurrency, total] as [string, number]];
            }

            if (!accountCurrencies) {
                return null;
            }

            const totals: Record<string, number> = {};
            payload.forEach((item) => {
                const accountId = String(item.dataKey || item.name || '');
                const currency = accountCurrencies[accountId] || 'USD';
                const value =
                    typeof item.value === 'number' ? item.value : 0;
                totals[currency] = (totals[currency] || 0) + value;
            });

            return Object.entries(totals).sort((a, b) => b[1] - a[1]);
        }, [payload, accountCurrencies, displayCurrency, netWorthMode]);

        if (!active || !payload?.length) {
            return null;
        }

        const nestLabel = payload.length === 1 && indicator !== 'dot';
        const hasMultipleCurrencies =
            currencyTotals && currencyTotals.length > 1;

        return (
            <div
                ref={ref}
                className={cn(
                    'border-border/50 bg-background grid min-w-[8rem] items-start gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs shadow-xl',
                    className,
                )}
            >
                {!nestLabel ? tooltipLabel : null}
                <div className="grid gap-1.5">
                    {payload.map(
                        (item: TooltipPayloadItem, index: number) => {
                            const key = `${nameKey || item.name || item.dataKey || 'value'}`;
                            const itemConfig = getPayloadConfigFromPayload(
                                config,
                                item,
                                key,
                            );
                            const accountId = String(
                                item.dataKey || item.name || '',
                            );

                            // In net worth mode, use the original unscaled
                            // value stored in the data point for display.
                            const displayValue = netWorthMode
                                ? ((item.payload?.[`${accountId}_display`] as number | undefined) ?? item.value)
                                : item.value;

                            return (
                                <div
                                    key={String(item.dataKey)}
                                    className={cn(
                                        'flex w-full flex-wrap items-stretch gap-2 [&>svg]:h-2.5 [&>svg]:w-2.5 [&>svg]:text-muted-foreground',
                                        indicator === 'dot' && 'items-center',
                                    )}
                                >
                                    {formatter &&
                                        item?.value !== undefined &&
                                        item.name ? (
                                        formatter(
                                            item.value,
                                            item.name,
                                            item,
                                            index,
                                            item.payload || {},
                                        )
                                    ) : (
                                        <>
                                            {itemConfig?.icon ? (
                                                <itemConfig.icon />
                                            ) : null}
                                            <div
                                                className={cn(
                                                    'flex flex-1 gap-4 justify-between leading-none',
                                                    nestLabel ? 'items-end' : '',
                                                )}
                                            >
                                                <div className="flex items-center gap-2">
                                                    {nestLabel
                                                        ? tooltipLabel
                                                        : <div style={{ backgroundColor: item.color }} className={cn([
                                                            'size-2.5 rounded-xs'
                                                        ])}></div>}
                                                    <span className="text-muted-foreground ml-0">
                                                        {itemConfig?.label ||
                                                            item.name}
                                                    </span>
                                                </div>
                                                {item.value !== undefined && (
                                                    <span className="text-foreground font-mono font-medium tabular-nums">
                                                        {(() => {
                                                            const originalKey = `${accountId}_original`;
                                                            const original = item.payload?.[originalKey] as { amount: number; currency_code: string } | undefined;
                                                            if (original) {
                                                                return (
                                                                    <span className="text-muted-foreground mr-1 text-[10px]">
                                                                        ({formatCurrencyWithCode(original.amount, original.currency_code, locale)})
                                                                    </span>
                                                                );
                                                            }
                                                            return null;
                                                        })()}
                                                        {valueFormatter
                                                            ? valueFormatter(
                                                                displayValue as number,
                                                                accountId,
                                                            )
                                                            : typeof displayValue ===
                                                                'number'
                                                                ? displayValue.toLocaleString(locale)
                                                                : displayValue}
                                                    </span>
                                                )}
                                            </div>
                                        </>
                                    )}
                                </div>
                            );
                        },
                    )}
                    {(() => {
                        const liabilitiesTotal = netWorthMode
                            ? (payload[0]?.payload?.__liabilities_total as number | undefined)
                            : undefined;
                        const liabilitiesJson = netWorthMode
                            ? (payload[0]?.payload?.__liabilities as string | undefined)
                            : undefined;
                        const liabilities: Array<{ name: string; amount: number }> = liabilitiesJson
                            ? (JSON.parse(liabilitiesJson) as Array<{ name: string; amount: number }>)
                            : [];
                        const hasLiabilities = typeof liabilitiesTotal === 'number' && liabilitiesTotal > 0;
                        const showTotalSection = payload.length > 1 || hasLiabilities;

                        if (!showTotalSection) return null;

                        const totalLabel = hasLiabilities ? 'Net Worth' : 'Total';

                        return (
                            <div className="border-border/50 flex flex-col gap-1 border-t pt-1.5">
                                {hasLiabilities && displayCurrency && liabilities.map((liability, index) => (
                                    <div key={index} className="flex justify-between gap-2">
                                        <div className="flex items-center gap-2">
                                            <div
                                                className="size-2.5 shrink-0 rounded-xs"
                                                style={{ backgroundColor: netWorthMode?.liabilityDotColor ?? 'var(--color-destructive)' }}
                                            />
                                            <span className="text-muted-foreground font-medium">
                                                {netWorthMode?.liabilityTypeLabel}: {liability.name}
                                            </span>
                                        </div>
                                        <span className="text-foreground font-mono font-medium tabular-nums">
                                            {isPrivacyModeEnabled
                                                ? formatCurrencyWithCode(-liability.amount, displayCurrency, locale).replace(/\d/g, '*')
                                                : formatCurrencyWithCode(-liability.amount, displayCurrency, locale)}
                                        </span>
                                    </div>
                                ))}
                                {hasMultipleCurrencies ? (
                                    currencyTotals.map(([currency, total]) => (
                                        <div
                                            key={currency}
                                            className="flex justify-between"
                                        >
                                            <span className="text-muted-foreground font-medium">
                                                {totalLabel} {currency}
                                            </span>
                                            <span className="text-foreground font-mono font-medium tabular-nums">
                                                {isPrivacyModeEnabled
                                                    ? formatCurrencyWithCode(total, currency, locale).replace(/\d/g, '*')
                                                    : formatCurrencyWithCode(total, currency, locale)}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <div className="flex justify-between">
                                        <span className="text-muted-foreground font-medium">
                                            {totalLabel}
                                        </span>
                                        <span className="text-foreground font-mono font-medium tabular-nums">
                                            {currencyTotals && currencyTotals[0]
                                                ? isPrivacyModeEnabled
                                                    ? formatCurrencyWithCode(currencyTotals[0][1], currencyTotals[0][0], locale).replace(/\d/g, '*')
                                                    : formatCurrencyWithCode(currencyTotals[0][1], currencyTotals[0][0], locale)
                                                : payload
                                                    .reduce(
                                                        (
                                                            sum: number,
                                                            item: TooltipPayloadItem,
                                                        ) => {
                                                            const value =
                                                                item.value;
                                                            return (
                                                                sum +
                                                                (typeof value ===
                                                                    'number'
                                                                    ? value
                                                                    : 0)
                                                            );
                                                        },
                                                        0,
                                                    )
                                                    .toLocaleString(locale)}
                                        </span>
                                    </div>
                                )}
                            </div>
                        );
                    })()}
                </div>
            </div>
        );
    },
);
ChartTooltipContent.displayName = 'ChartTooltip';

const ChartLegend = RechartsPrimitive.Legend;

interface LegendPayloadItem {
    value?: string;
    dataKey?: string | number;
    color?: string;
}

interface ChartLegendContentProps {
    className?: string;
    hideIcon?: boolean;
    payload?: LegendPayloadItem[];
    verticalAlign?: 'top' | 'bottom';
    nameKey?: string;
}

const ChartLegendContent = React.forwardRef<
    HTMLDivElement,
    ChartLegendContentProps
>(
    (
        {
            className,
            hideIcon = false,
            payload,
            verticalAlign = 'bottom',
            nameKey,
        },
        ref,
    ) => {
        const { config } = useChart();

        if (!payload?.length) {
            return null;
        }

        return (
            <div
                ref={ref}
                className={cn(
                    'flex items-center justify-center gap-4',
                    verticalAlign === 'top' ? 'pb-3' : 'pt-3',
                    className,
                )}
            >
                {payload.map((item: LegendPayloadItem) => {
                    const key = `${nameKey || item.dataKey || 'value'}`;
                    const itemConfig = getPayloadConfigFromPayload(
                        config,
                        item,
                        key,
                    );

                    return (
                        <div
                            key={item.value}
                            className={cn(
                                'flex items-center gap-1.5 [&>svg]:h-3 [&>svg]:w-3 [&>svg]:text-muted-foreground',
                            )}
                        >
                            {itemConfig?.icon && !hideIcon ? (
                                <itemConfig.icon />
                            ) : (
                                <div
                                    className="h-2 w-2 shrink-0 rounded-[2px]"
                                    style={{
                                        backgroundColor: item.color,
                                    }}
                                />
                            )}
                            {itemConfig?.label}
                        </div>
                    );
                })}
            </div>
        );
    },
);
ChartLegendContent.displayName = 'ChartLegend';

function getPayloadConfigFromPayload(
    config: ChartConfig,
    payload: unknown,
    key: string,
) {
    if (typeof payload !== 'object' || payload === null) {
        return undefined;
    }

    const payloadPayload =
        'payload' in payload &&
            typeof payload.payload === 'object' &&
            payload.payload !== null
            ? payload.payload
            : undefined;

    let configLabelKey: string = key;

    if (
        key in payload &&
        typeof (payload as Record<string, unknown>)[key] === 'string'
    ) {
        configLabelKey = (payload as Record<string, unknown>)[key] as string;
    } else if (
        payloadPayload &&
        key in payloadPayload &&
        typeof (payloadPayload as Record<string, unknown>)[key] === 'string'
    ) {
        configLabelKey = (payloadPayload as Record<string, unknown>)[
            key
        ] as string;
    }

    return configLabelKey in config
        ? config[configLabelKey]
        : config[key as keyof typeof config];
}

export {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
    ChartLegend,
    ChartLegendContent,
    ChartStyle,
};

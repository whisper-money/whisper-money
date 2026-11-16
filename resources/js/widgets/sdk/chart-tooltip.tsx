import { cn } from '@/lib/utils';
import { maskDigits } from '@/utils/currency';
import { type ReactNode } from 'react';
import { Tooltip } from 'recharts';
import { hasExternalUrl } from '../validator/allowlist';
import { useWidgetEnv } from './context';

type Formatter = (value: unknown, name?: unknown) => unknown;

interface TooltipItem {
    name?: unknown;
    value?: unknown;
    color?: unknown;
    dataKey?: unknown;
}

interface ContentProps {
    active?: boolean;
    payload?: readonly TooltipItem[];
    label?: unknown;
}

interface ChartTooltipProps {
    /** `(value, name) => text` for each series' value. */
    valueFormatter?: unknown;
    /** `(label) => text` for the hovered point's label. */
    labelFormatter?: unknown;
    hideLabel?: unknown;
    indicator?: unknown;
    cursor?: unknown;
}

/** What a widget's formatter returns, as text: never a node it built. */
function formatWith(formatter: unknown, ...values: unknown[]): string {
    const formatted =
        typeof formatter === 'function'
            ? (formatter as Formatter)(...(values as [unknown, unknown]))
            : values[0];

    return formatted === null || formatted === undefined
        ? ''
        : String(formatted);
}

/** A series color, unless it would load something. */
function safeColor(color: unknown): string | undefined {
    return typeof color === 'string' && !hasExternalUrl(color)
        ? color
        : undefined;
}

/** The tooltip box; exported for tests, widgets get `<ChartTooltip>`. */
export function ChartTooltipContent({
    active,
    payload,
    label,
    valueFormatter,
    labelFormatter,
    hideLabel,
    indicator,
}: ContentProps & ChartTooltipProps): ReactNode {
    const { privacyMode } = useWidgetEnv();

    if (!active || !payload?.length) {
        return null;
    }

    const title = hideLabel === true ? '' : formatWith(labelFormatter, label);

    return (
        <div className="grid min-w-32 gap-1.5 rounded-lg border border-border/50 bg-background px-2.5 py-1.5 text-xs shadow-xl">
            {title !== '' && <div className="font-medium">{title}</div>}
            {payload.map((item, index) => (
                <div
                    key={`${String(item.dataKey ?? item.name)}-${index}`}
                    className="flex items-center gap-2"
                >
                    <span
                        className={cn(
                            'shrink-0',
                            indicator === 'line'
                                ? 'h-3 w-1 rounded-full'
                                : 'size-2.5 rounded-xs',
                        )}
                        style={{ backgroundColor: safeColor(item.color) }}
                    />
                    <span className="min-w-0 flex-1 truncate text-muted-foreground">
                        {formatWith(undefined, item.name)}
                    </span>
                    <span className="font-mono font-medium text-foreground tabular-nums">
                        {privacyMode
                            ? maskDigits(
                                  formatWith(
                                      valueFormatter,
                                      item.value,
                                      item.name,
                                  ),
                              )
                            : formatWith(valueFormatter, item.value, item.name)}
                    </span>
                </div>
            ))}
        </div>
    );
}

/**
 * Recharts' `<Tooltip>` with our styling, in place of `<Tooltip content>`,
 * which the validator rejects (a render prop gets the chart's internals).
 * The widget only passes formatters, which receive values and return text;
 * values are masked while privacy mode is on.
 */
export function ChartTooltip({
    valueFormatter,
    labelFormatter,
    hideLabel,
    indicator,
    cursor,
}: ChartTooltipProps) {
    return (
        <Tooltip
            cursor={cursor !== false}
            isAnimationActive={false}
            content={(props) => (
                <ChartTooltipContent
                    active={props.active}
                    payload={props.payload as readonly TooltipItem[]}
                    label={props.label}
                    valueFormatter={valueFormatter}
                    labelFormatter={labelFormatter}
                    hideLabel={hideLabel}
                    indicator={indicator}
                />
            )}
        />
    );
}

import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import { AnimatedCollapse } from '@/components/ui/animated-collapse';
import { Progress } from '@/components/ui/progress';
import { type ExpandableCategories } from '@/hooks/use-expandable-categories';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ChevronsDown, ChevronsUp, Loader2, Minus } from 'lucide-react';
import { type ReactNode } from 'react';

export interface BreakdownTrend {
    change: number;
    previousAmount: number;
    currentAmount: number;
}

/**
 * Maps an arbitrary item to the fields a breakdown row renders. Each widget
 * (dashboard categories, cash-flow income/expenses, the analysis drawer's
 * categories, tags and accounts) supplies one of these so they can all share
 * the exact same row: a leading marker, a truncated name, an optional trend
 * and percentage, the amount, and a proportional bar underneath.
 */
export interface CategoryBreakdownAdapter<T> {
    getId: (item: T) => string;
    getKey: (item: T, index: number) => string;
    getName: (item: T) => string;
    getAmount: (item: T) => number;
    /** Bar fill, 0–100. */
    getPercentage: (item: T) => number;
    getBarColor: (item: T, index: number) => string;
    renderLeading: (item: T, index: number) => ReactNode;
    getHref?: (item: T) => string | null;
    getTrend?: (item: T) => BreakdownTrend | null;
    canExpand?: (item: T) => boolean;
}

interface CategoryBreakdownRowProps<T> {
    item: T;
    index: number;
    currencyCode: string;
    adapter: CategoryBreakdownAdapter<T>;
    expandable?: ExpandableCategories<T>;
    /** Render the expand/collapse gutter (and its placeholder) on every row. */
    expandColumn?: boolean;
    showPercentage?: boolean;
    invertTrendColors?: boolean;
}

export function CategoryBreakdownRow<T>({
    item,
    index,
    currencyCode,
    adapter,
    expandable,
    expandColumn = false,
    showPercentage = false,
    invertTrendColors = false,
}: CategoryBreakdownRowProps<T>) {
    const id = adapter.getId(item);
    const percentage = adapter.getPercentage(item);
    const href = adapter.getHref?.(item) ?? null;
    const trend = adapter.getTrend?.(item) ?? null;

    const canExpand = Boolean(expandable && adapter.canExpand?.(item));
    const expanded = canExpand && expandable!.isExpanded(id);
    const loading = canExpand && expandable!.isLoading(id);

    const header = (
        <div className="flex min-w-0 items-center gap-2">
            {adapter.renderLeading(item, index)}
            <span className="min-w-0 flex-1 truncate text-sm font-medium">
                {adapter.getName(item)}
            </span>
            {trend && (
                <PercentageTrendIndicator
                    trend={trend.change}
                    label=""
                    previousAmount={trend.previousAmount}
                    currentAmount={trend.currentAmount}
                    currencyCode={currencyCode}
                    invertColors={invertTrendColors}
                    className="shrink-0 text-xs"
                />
            )}
            {showPercentage && (
                <span className="hidden shrink-0 text-xs text-muted-foreground sm:inline">
                    {percentage.toFixed(0)}%
                </span>
            )}
            <AmountDisplay
                amountInCents={adapter.getAmount(item)}
                currencyCode={currencyCode}
                variant="compact"
                minimumFractionDigits={0}
                maximumFractionDigits={0}
                className="shrink-0 whitespace-nowrap"
            />
        </div>
    );

    return (
        <div className="space-y-2">
            <div className="flex items-center gap-0">
                {expandColumn &&
                    (canExpand ? (
                        <button
                            type="button"
                            onClick={() => expandable!.toggle(id)}
                            aria-expanded={expanded}
                            aria-label={
                                expanded
                                    ? __('Hide subcategories')
                                    : __('Show subcategories')
                            }
                            className="flex size-6 shrink-0 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground"
                        >
                            {loading ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : expanded ? (
                                <ChevronsUp className="size-4" />
                            ) : (
                                <ChevronsDown className="size-4" />
                            )}
                        </button>
                    ) : (
                        <span
                            aria-hidden="true"
                            className="flex size-6 shrink-0 items-center justify-center text-muted-foreground/30"
                        >
                            <Minus className="size-4" />
                        </span>
                    ))}
                {href ? (
                    <Link
                        href={href}
                        className="group block min-w-0 grow rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                    >
                        {header}
                    </Link>
                ) : (
                    <div className="min-w-0 grow px-1.5 py-1">{header}</div>
                )}
            </div>
            <Progress
                value={percentage}
                className="h-2 w-full"
                indicatorColor={adapter.getBarColor(item, index)}
            />

            {canExpand && (
                <AnimatedCollapse open={expanded}>
                    <div className="ml-[11px] space-y-2 border-l border-border pt-2 pl-3">
                        {expandable!
                            .getChildren(id)
                            .map((child, childIndex) => (
                                <CategoryBreakdownRow
                                    key={adapter.getKey(child, childIndex)}
                                    item={child}
                                    index={childIndex}
                                    currencyCode={currencyCode}
                                    adapter={adapter}
                                    expandable={expandable}
                                    expandColumn={expandColumn}
                                    showPercentage={showPercentage}
                                    invertTrendColors={invertTrendColors}
                                />
                            ))}
                    </div>
                </AnimatedCollapse>
            )}
        </div>
    );
}

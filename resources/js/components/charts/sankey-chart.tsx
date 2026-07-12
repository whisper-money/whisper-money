import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyData } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { groupSmallCategories } from '@/lib/sankey-utils';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronDown, ChevronRight } from 'lucide-react';
import {
    type ComponentProps,
    type KeyboardEvent,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { Layer, ResponsiveContainer, Sankey } from 'recharts';

interface SankeyChartProps {
    data: SankeyData;
    height?: number;
    className?: string;
    currency?: string;
    groupingThreshold?: number;
    period?: { from: Date; to: Date };
}

type FlowKind = 'income' | 'center' | 'expense';
type LabelSide = 'left' | 'right' | 'onbar';

// Fields we attach to each node; recharts spreads these onto the payload it
// hands back to the custom node renderer, alongside its own layout data.
interface FlowNode {
    name: string;
    amount: number;
    color: string;
    kind: FlowKind;
    labelSide: LabelSide;
    categoryId?: string;
    expandable?: boolean;
    expanded?: boolean;
}

interface FlowLink {
    source: number;
    target: number;
    value: number;
}

const NODE_WIDTH = 12;
const NODE_PADDING = 24;
const LABEL_GAP = 6;
const LABEL_HEIGHT = 30;
// On-bar labels (the hub, and an expanded parent) are bordered pills with two
// lines, so they need a little more room than the plain side labels.
const PILL_LABEL_HEIGHT = 44;
// A Sankey is inherently horizontal, so on narrow screens we let it scroll
// sideways (same pattern as the trend chart) rather than crushing the flows.
const MIN_CHART_WIDTH = 560;
// A drill-down adds a fourth column, so widen the canvas to keep it readable.
const EXPANDED_MIN_CHART_WIDTH = 760;
// Gives each node enough vertical room that its two-line label stays legible
// even when a category's bar is tiny.
const ROW_HEIGHT = 46;
const MUTED_COLOR = 'var(--color-muted)';
const CENTER_COLOR = 'var(--color-chart-1)';

export function SankeyChart({
    data,
    height = 400,
    className,
    currency = 'USD',
    groupingThreshold = 0.03,
    period,
}: SankeyChartProps) {
    const [containerWidth, setContainerWidth] = useState(600);
    const [expandedId, setExpandedId] = useState<string | null>(null);
    const [childrenById, setChildrenById] = useState<
        Record<string, SankeyData>
    >({});
    const containerRef = useRef<HTMLDivElement>(null);
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const { cashflowIncomeColor, cashflowExpenseColor, categoryBarColor } =
        useChartColors();

    const periodKey = period
        ? `${period.from.getTime()}-${period.to.getTime()}`
        : '';

    const maskIfPrivate = (value: number): string => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
    };

    const toggleExpand = (categoryId: string) => {
        setExpandedId((previous) =>
            previous === categoryId ? null : categoryId,
        );
    };

    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const updateWidth = () => setContainerWidth(container.clientWidth);
        updateWidth();

        if (typeof ResizeObserver === 'undefined') {
            window.addEventListener('resize', updateWidth);

            return () => window.removeEventListener('resize', updateWidth);
        }

        const observer = new ResizeObserver(updateWidth);
        observer.observe(container);

        return () => observer.disconnect();
    }, []);

    // A new period (or refreshed data) invalidates any open drill-down.
    useEffect(() => {
        setExpandedId(null);
        setChildrenById({});
    }, [periodKey]);

    // Lazily fetch the subcategories of the expanded parent.
    useEffect(() => {
        if (!period || !expandedId || childrenById[expandedId]) {
            return;
        }

        const from = format(period.from, 'yyyy-MM-dd');
        const to = format(period.to, 'yyyy-MM-dd');
        let cancelled = false;

        fetch(`/api/cashflow/sankey?from=${from}&to=${to}&parent=${expandedId}`)
            .then((response) => response.json())
            .then((json: SankeyData) => {
                if (!cancelled) {
                    setChildrenById((previous) => ({
                        ...previous,
                        [expandedId]: json,
                    }));
                }
            })
            .catch((error) => {
                // Collapse back so the node doesn't stay stuck "expanded" with
                // no subcategories and no way forward.
                if (!cancelled) {
                    setExpandedId((current) =>
                        current === expandedId ? null : current,
                    );
                }
                console.error('Failed to fetch subcategories:', error);
            });

        return () => {
            cancelled = true;
        };
    }, [expandedId, childrenById, period, periodKey]);

    const { chartData, isEmpty, nodeRows, subcatRows } = useMemo(() => {
        const {
            income_categories,
            expense_categories,
            total_income,
            total_expense,
        } = data;

        const nodes: FlowNode[] = [];
        const links: FlowLink[] = [];

        const groupedIncome = groupSmallCategories(
            income_categories,
            total_income,
            groupingThreshold,
        );
        groupedIncome.main.forEach((item, index) => {
            if (item.amount <= 0) {
                return;
            }

            nodes.push({
                name: item.category.name,
                amount: item.amount,
                color: categoryBarColor(item.category.color, index),
                kind: 'income',
                labelSide: 'left',
                categoryId: item.category.id,
            });
        });
        if (groupedIncome.other) {
            nodes.push({
                name: __('Other'),
                amount: groupedIncome.other.total,
                color: MUTED_COLOR,
                kind: 'income',
                labelSide: 'left',
            });
        }

        const centerIndex = nodes.length;
        nodes.push({
            // "Net" rather than "Cashflow": the card title already reads
            // "Cashflow", so the hub only needs to carry the net amount.
            name: __('Net'),
            amount: total_income - total_expense,
            color: CENTER_COLOR,
            kind: 'center',
            labelSide: 'onbar',
        });

        const groupedExpense = groupSmallCategories(
            expense_categories,
            total_expense,
            groupingThreshold,
        );
        groupedExpense.main.forEach((item, index) => {
            if (item.amount <= 0) {
                return;
            }

            const isExpanded = item.category.id === expandedId;
            // Keep the label beside the bar until the subcategories actually
            // load, so it doesn't jump onto the bar and back during the fetch.
            const childrenLoaded =
                isExpanded &&
                (childrenById[item.category.id]?.expense_categories?.length ??
                    0) > 0;

            nodes.push({
                name: item.category.name,
                amount: item.amount,
                color: categoryBarColor(item.category.color, index),
                kind: 'expense',
                // An expanded parent sits between the hub and its subcategory
                // column, so its label moves onto the bar to clear the way.
                labelSide: childrenLoaded ? 'onbar' : 'right',
                categoryId: item.category.id,
                expandable: !!item.has_children,
                expanded: isExpanded,
            });
        });
        if (groupedExpense.other) {
            nodes.push({
                name: __('Other'),
                amount: groupedExpense.other.total,
                color: MUTED_COLOR,
                kind: 'expense',
                labelSide: 'right',
            });
        }

        nodes.forEach((node, index) => {
            if (node.amount <= 0) {
                return;
            }

            if (node.kind === 'income') {
                links.push({
                    source: index,
                    target: centerIndex,
                    value: node.amount,
                });
            } else if (node.kind === 'expense') {
                links.push({
                    source: centerIndex,
                    target: index,
                    value: node.amount,
                });
            }
        });

        // Drill-down: split the expanded parent into its subcategory column.
        let subcatRows = 0;
        if (expandedId) {
            const parentIndex = nodes.findIndex(
                (node) =>
                    node.kind === 'expense' && node.categoryId === expandedId,
            );
            const kids = [
                ...(childrenById[expandedId]?.expense_categories ?? []),
            ].sort((a, b) => b.amount - a.amount);

            if (parentIndex >= 0) {
                kids.forEach((kid, index) => {
                    if (kid.amount <= 0) {
                        return;
                    }

                    const childIndex = nodes.length;
                    nodes.push({
                        name: kid.category.name,
                        amount: kid.amount,
                        color: categoryBarColor(kid.category.color, index),
                        kind: 'expense',
                        labelSide: 'right',
                        categoryId: kid.category.id,
                    });
                    links.push({
                        source: parentIndex,
                        target: childIndex,
                        value: kid.amount,
                    });
                    subcatRows += 1;
                });
            }
        }

        const incomeRows =
            groupedIncome.main.length + (groupedIncome.other ? 1 : 0);
        const expenseRows =
            groupedExpense.main.length + (groupedExpense.other ? 1 : 0);

        return {
            chartData: { nodes, links },
            isEmpty: links.length === 0,
            nodeRows: Math.max(incomeRows, expenseRows, subcatRows),
            subcatRows,
        };
    }, [data, groupingThreshold, categoryBarColor, expandedId, childrenById]);

    if (isEmpty) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center text-muted-foreground',
                    className,
                )}
                style={{ height }}
            >
                {__('No cashflow data for this period')}
            </div>
        );
    }

    const labelWidth = Math.max(
        64,
        Math.min(140, Math.round(containerWidth * 0.26)),
    );
    const sideMargin = labelWidth + LABEL_GAP;
    // Grow the canvas so crowded sides (many expense categories) keep their
    // labels legible instead of overlapping.
    const chartHeight = Math.max(height, nodeRows * ROW_HEIGHT + 24);
    const minChartWidth =
        subcatRows > 0 ? EXPANDED_MIN_CHART_WIDTH : MIN_CHART_WIDTH;

    const goToCategory = (categoryId: string) => {
        if (!period) {
            return;
        }

        router.visit(
            transactionsIndex({
                query: {
                    category_ids: categoryId,
                    date_from: format(period.from, 'yyyy-MM-dd'),
                    date_to: format(period.to, 'yyyy-MM-dd'),
                },
            }).url,
        );
    };

    const renderNode = ({
        x,
        y,
        width,
        height: nodeHeight,
        index,
        payload,
    }: {
        x: number;
        y: number;
        width: number;
        height: number;
        index: number;
        payload: FlowNode;
    }) => {
        const node = payload;
        const isPill = node.labelSide === 'onbar';
        const expandable = !!node.expandable && !!node.categoryId && !!period;
        const navigable = !expandable && !!node.categoryId && !!period;
        const interactive = expandable || navigable;

        const activate = () => {
            if (expandable) {
                toggleExpand(node.categoryId!);
            } else if (navigable) {
                goToCategory(node.categoryId!);
            }
        };

        const labelBoxHeight = isPill ? PILL_LABEL_HEIGHT : LABEL_HEIGHT;
        const labelY = y + nodeHeight / 2 - labelBoxHeight / 2;
        let labelX: number;
        let labelBoxWidth: number;
        let alignClass: string;

        if (node.labelSide === 'left') {
            labelX = 2;
            labelBoxWidth = Math.max(0, x - LABEL_GAP - 2);
            alignClass = 'items-end text-right';
        } else if (node.labelSide === 'right') {
            labelX = x + width + LABEL_GAP;
            // Cap the width so a non-rightmost parent (one sitting to the left
            // of an expanded subcategory column) can't stretch its label across
            // that column.
            labelBoxWidth = Math.max(
                0,
                Math.min(labelWidth, containerWidth - labelX - 2),
            );
            alignClass = 'items-start text-left';
        } else {
            labelBoxWidth = labelWidth;
            labelX = x + width / 2 - labelWidth / 2;
            alignClass = 'items-center text-center';
        }

        const ChevronIcon = node.expanded ? ChevronDown : ChevronRight;

        return (
            <Layer
                key={`node-${index}`}
                className={cn(interactive && 'cursor-pointer')}
                role={expandable ? 'button' : navigable ? 'link' : undefined}
                tabIndex={interactive ? 0 : undefined}
                aria-label={
                    expandable
                        ? node.expanded
                            ? `Collapse ${node.name}`
                            : `Expand ${node.name}`
                        : navigable
                          ? `View ${node.name} transactions`
                          : undefined
                }
                onClick={interactive ? activate : undefined}
                onKeyDown={
                    interactive
                        ? (event: KeyboardEvent) => {
                              if (event.key === 'Enter' || event.key === ' ') {
                                  event.preventDefault();
                                  activate();
                              }
                          }
                        : undefined
                }
            >
                <rect
                    x={x}
                    y={y}
                    width={width}
                    height={nodeHeight}
                    rx={2}
                    fill={node.color}
                    fillOpacity={0.9}
                />
                <foreignObject
                    x={labelX}
                    y={labelY}
                    width={labelBoxWidth}
                    height={labelBoxHeight}
                    className="overflow-visible"
                >
                    <div
                        className={cn(
                            'flex h-full flex-col justify-center gap-0.5 leading-tight',
                            alignClass,
                            isPill &&
                                'rounded-md border border-border bg-background/90 px-1.5 py-0.5 shadow-sm',
                        )}
                    >
                        <div
                            className={cn(
                                'flex max-w-full items-center gap-1',
                                node.labelSide === 'left' && 'flex-row-reverse',
                            )}
                        >
                            <span
                                title={node.name}
                                className="min-w-0 truncate text-[11px] font-medium text-foreground"
                            >
                                {node.name}
                            </span>
                            {expandable && (
                                <ChevronIcon
                                    aria-hidden="true"
                                    className="size-3 shrink-0 text-muted-foreground"
                                />
                            )}
                        </div>
                        <span className="text-[11px] text-muted-foreground">
                            {maskIfPrivate(node.amount)}
                        </span>
                    </div>
                </foreignObject>
            </Layer>
        );
    };

    const renderLink = ({
        sourceX,
        sourceY,
        sourceControlX,
        targetX,
        targetY,
        targetControlX,
        linkWidth,
        index,
        payload,
    }: {
        sourceX: number;
        sourceY: number;
        sourceControlX: number;
        targetX: number;
        targetY: number;
        targetControlX: number;
        linkWidth: number;
        index: number;
        payload: { source: FlowNode; target: FlowNode };
    }) => {
        const kind =
            payload.source.kind === 'center'
                ? payload.target.kind
                : payload.source.kind;
        const stroke =
            kind === 'income' ? cashflowIncomeColor : cashflowExpenseColor;

        return (
            <path
                key={`link-${index}`}
                d={`M${sourceX},${sourceY} C${sourceControlX},${sourceY} ${targetControlX},${targetY} ${targetX},${targetY}`}
                fill="none"
                stroke={stroke}
                strokeWidth={Math.max(1, linkWidth)}
                strokeOpacity={0.4}
            />
        );
    };

    return (
        <div className={cn('w-full overflow-x-auto', className)}>
            <div ref={containerRef} style={{ minWidth: minChartWidth }}>
                <ResponsiveContainer width="100%" height={chartHeight}>
                    <Sankey
                        data={chartData}
                        node={
                            renderNode as ComponentProps<typeof Sankey>['node']
                        }
                        link={
                            renderLink as ComponentProps<typeof Sankey>['link']
                        }
                        nodeWidth={NODE_WIDTH}
                        nodePadding={NODE_PADDING}
                        sort={false}
                        // 'left' keeps sink nodes at their natural depth instead
                        // of shoving them all into the last column, so an
                        // expanded parent's subcategories get their own column
                        // and line up beside it rather than crossing every flow.
                        align="left"
                        margin={{
                            top: 12,
                            right: sideMargin,
                            bottom: 12,
                            left: sideMargin,
                        }}
                    />
                </ResponsiveContainer>
            </div>
        </div>
    );
}

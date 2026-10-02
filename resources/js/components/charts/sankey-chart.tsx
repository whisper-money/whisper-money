import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyCategory, SankeyData } from '@/hooks/use-cashflow-data';
import { useChartColors } from '@/hooks/use-chart-color-scheme';
import { useLocale } from '@/hooks/use-locale';
import { fetchJson } from '@/lib/fetch-json';
import {
    type GroupedCategory,
    formatShare,
    groupSmallCategories,
} from '@/lib/sankey-utils';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronDown, ChevronLeft, ChevronRight } from 'lucide-react';
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
    // Pre-formatted share of this node's denominator (its side total, or its
    // parent's amount when it is a drill-down child). Null when there is none.
    share: string | null;
    categoryId?: string;
    expandable?: boolean;
    expanded?: boolean;
    // A drill-down child that netted to the other side: it takes its (negative)
    // amount off the parent, so it sits in the subcategory column with a label,
    // an empty bar and a dashed connector instead of a flow.
    offset?: boolean;
}

interface FlowLink {
    source: number;
    target: number;
    value: number;
}

const NODE_WIDTH = 12;
const LABEL_GAP = 6;
const LABEL_HEIGHT = 30;
// recharts stacks same-column nodes with exactly this vertical gap, so tiny
// bars end up NODE_PADDING apart regardless of the canvas height. Keep it above
// LABEL_HEIGHT (+ breathing room) so two adjacent labels can never overlap.
const NODE_PADDING = LABEL_HEIGHT + 6;
// On-bar labels are bordered pills, so they need a little more room than the
// plain side labels. The hub carries a third line with the savings rate; an
// expanded parent is the plain label plus the pill's border and padding, and
// keeping it to that is what stops it from covering the node below.
const PILL_LABEL_HEIGHT = 58;
const PARENT_PILL_HEIGHT = LABEL_HEIGHT + 10;
// A Sankey is inherently horizontal, so on narrow screens we let it scroll
// sideways (same pattern as the trend chart) rather than crushing the flows.
const MIN_CHART_WIDTH = 560;
// A drill-down adds a fourth column, so widen the canvas to keep it readable.
const EXPANDED_MIN_CHART_WIDTH = 760;
// Gives each node enough vertical room that its two-line label stays legible
// even when a category's bar is tiny.
const ROW_HEIGHT = 46;
// "Other" is a bucket, not a category, so it opens on a sentinel id. Nothing
// may look it up on the API: `parent` only takes a uuid there.
const OTHER_ID = 'other';
const MUTED_COLOR = 'var(--color-muted)';
const OFFSET_COLOR = 'var(--color-muted-foreground)';
// An offset has no flow, so it gets an empty, dashed bar of this height to
// anchor its label in the subcategory column, tied to its parent by a dashed
// connector of this width.
const OFFSET_MARKER_HEIGHT = 14;
const OFFSET_LINK_WIDTH = 2;
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
    // A category id can appear on both sides (in- and outflows), so the open
    // drill-down is keyed by side too, not just id.
    const [expanded, setExpanded] = useState<{
        id: string;
        kind: FlowKind;
    } | null>(null);
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

    const expandedId = expanded?.id ?? null;
    const expandedKind = expanded?.kind ?? null;

    const toggleExpand = (categoryId: string, kind: FlowKind) => {
        setExpanded((previous) =>
            previous?.id === categoryId && previous.kind === kind
                ? null
                : { id: categoryId, kind },
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
        setExpanded(null);
        setChildrenById({});
    }, [periodKey]);

    // Lazily fetch the subcategories of the expanded parent.
    useEffect(() => {
        if (
            !period ||
            !expandedId ||
            expandedId === OTHER_ID ||
            childrenById[expandedId]
        ) {
            return;
        }

        const from = format(period.from, 'yyyy-MM-dd');
        const to = format(period.to, 'yyyy-MM-dd');
        let cancelled = false;

        fetchJson<SankeyData>(
            `/api/cashflow/sankey?from=${from}&to=${to}&parent=${expandedId}`,
        )
            .then((json) => {
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
                    setExpanded((current) =>
                        current?.id === expandedId ? null : current,
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
        // Both sides build identical node shapes; only the children key and
        // the collapsed-label side (income to the left of the hub, expense to
        // the right) differ.
        const pushCategoryNodes = (
            items: SankeyCategory[],
            kind: 'income' | 'expense',
        ) => {
            const collapsedSide: LabelSide =
                kind === 'income' ? 'left' : 'right';
            const childrenKey =
                kind === 'income' ? 'income_categories' : 'expense_categories';
            const sideTotal = kind === 'income' ? total_income : total_expense;

            items.forEach((item, index) => {
                if (item.amount <= 0) {
                    return;
                }

                const isExpanded =
                    expandedKind === kind && item.category.id === expandedId;
                // Keep the label beside the bar until the subcategories
                // actually load, so it doesn't jump onto the bar and back
                // during the fetch; an expanded parent's label moves onto the
                // bar to clear room for its subcategory column.
                const childrenLoaded =
                    isExpanded &&
                    (childrenById[item.category.id]?.[childrenKey]?.length ??
                        0) > 0;

                nodes.push({
                    name: item.category.name,
                    amount: item.amount,
                    color: categoryBarColor(item.category.color, index),
                    kind,
                    labelSide: childrenLoaded ? 'onbar' : collapsedSide,
                    share: formatShare(item.amount, sideTotal),
                    categoryId: item.category.id,
                    expandable: !!item.has_children,
                    expanded: isExpanded,
                });
            });
        };

        // "Other" expands like a parent, except its children are already in
        // memory: they are the categories the grouping folded away.
        const pushOtherNode = (
            other: GroupedCategory,
            kind: 'income' | 'expense',
        ) => {
            const isExpanded = expandedKind === kind && expandedId === OTHER_ID;
            const collapsedSide: LabelSide =
                kind === 'income' ? 'left' : 'right';
            const sideTotal = kind === 'income' ? total_income : total_expense;

            nodes.push({
                name: __('Other'),
                amount: other.total,
                color: MUTED_COLOR,
                kind,
                labelSide: isExpanded ? 'onbar' : collapsedSide,
                share: formatShare(other.total, sideTotal),
                categoryId: OTHER_ID,
                expandable: true,
                expanded: isExpanded,
            });
        };

        pushCategoryNodes(groupedIncome.main, 'income');
        if (groupedIncome.other) {
            pushOtherNode(groupedIncome.other, 'income');
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
            // The hub's share is the savings rate, so it reads against income
            // rather than against a side total.
            share: formatShare(total_income - total_expense, total_income),
        });

        const groupedExpense = groupSmallCategories(
            expense_categories,
            total_expense,
            groupingThreshold,
        );
        pushCategoryNodes(groupedExpense.main, 'expense');
        if (groupedExpense.other) {
            pushOtherNode(groupedExpense.other, 'expense');
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
        // Expenses flow out of the hub (subcategories extend to the right);
        // income flows into it (subcategories sit to the left), so the link
        // direction and label side mirror by side.
        let subcatRows = 0;
        if (expandedId && expandedKind) {
            const parentIndex = nodes.findIndex(
                (node) =>
                    node.kind === expandedKind &&
                    node.categoryId === expandedId,
            );
            const drilled = childrenById[expandedId];
            const [sameSide, otherSide] =
                expandedKind === 'income'
                    ? [drilled?.income_categories, drilled?.expense_categories]
                    : [drilled?.expense_categories, drilled?.income_categories];
            const grouped =
                expandedKind === 'income' ? groupedIncome : groupedExpense;
            const byAmount = (a: SankeyCategory, b: SankeyCategory) =>
                b.amount - a.amount;
            const hasAmount = (child: SankeyCategory) => child.amount > 0;
            const kids = (
                expandedId === OTHER_ID
                    ? (grouped.other?.categories ?? [])
                    : (sameSide ?? [])
            )
                .filter(hasAmount)
                .sort(byAmount);
            // The parent nets its whole subtree before it picks a side, so a
            // child that netted to the other side was taken off the parent's
            // amount. Showing it as that offset is what makes the column add
            // up to the parent. "Other" only groups same-side categories.
            const offsets = (expandedId === OTHER_ID ? [] : (otherSide ?? []))
                .filter(hasAmount)
                .sort(byAmount);

            if (parentIndex >= 0) {
                const parentAmount = nodes[parentIndex].amount;
                // With an offset netted in, the subcategories add up to more
                // than their parent. Their flows are scaled down to it so the
                // parent's bar stays the size of the flow feeding it; the
                // labels keep the real amounts.
                const kidsTotal = kids.reduce(
                    (sum, kid) => sum + kid.amount,
                    0,
                );
                const flowScale =
                    kidsTotal > parentAmount ? parentAmount / kidsTotal : 1;

                const pushChild = (
                    child: SankeyCategory,
                    color: string,
                    offset = false,
                ) => {
                    const childIndex = nodes.length;
                    const amount = offset ? -child.amount : child.amount;
                    nodes.push({
                        name: child.category.name,
                        amount,
                        color,
                        kind: expandedKind,
                        labelSide: expandedKind === 'income' ? 'left' : 'right',
                        // A subcategory answers "how much of this parent",
                        // not "how much of everything", so the parent's
                        // amount is the denominator.
                        share: formatShare(amount, parentAmount),
                        categoryId: child.category.id,
                        offset,
                    });
                    // A sankey cannot draw a negative flow, so an offset gets
                    // an empty one: it still lands in the subcategory column,
                    // below the subcategories, with room for its label.
                    const value = offset ? 0 : child.amount * flowScale;
                    links.push(
                        expandedKind === 'income'
                            ? { source: childIndex, target: parentIndex, value }
                            : {
                                  source: parentIndex,
                                  target: childIndex,
                                  value,
                              },
                    );
                    subcatRows += 1;
                };

                kids.forEach((kid, index) =>
                    pushChild(kid, categoryBarColor(kid.category.color, index)),
                );
                offsets.forEach((child) =>
                    pushChild(child, OFFSET_COLOR, true),
                );
            }
        }

        const incomeRows =
            groupedIncome.main.length + (groupedIncome.other ? 1 : 0);
        const expenseRows =
            groupedExpense.main.length + (groupedExpense.other ? 1 : 0);

        // An income drill-down links children -> parent, so the subcategories
        // land in the same leftmost column as the (non-expanded) income
        // siblings rather than in their own column. That combined column — not
        // any single side — bounds the height; under-counting it would let
        // recharts derive a negative row scale and break the whole chart.
        // Expense subcategories get their own column, so the plain max holds.
        const leftmostRows =
            expandedKind === 'income'
                ? incomeRows - 1 + subcatRows
                : incomeRows;

        return {
            chartData: { nodes, links },
            isEmpty: links.length === 0,
            nodeRows: Math.max(leftmostRows, expenseRows, subcatRows),
            subcatRows,
        };
    }, [
        data,
        groupingThreshold,
        categoryBarColor,
        expandedId,
        expandedKind,
        childrenById,
    ]);

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
        const isNet = node.kind === 'center';
        const expandable = !!node.expandable && !!node.categoryId && !!period;
        const navigable = !expandable && !!node.categoryId && !!period;
        const interactive = expandable || navigable;

        const activate = () => {
            if (expandable) {
                toggleExpand(node.categoryId!, node.kind);
            } else if (navigable) {
                goToCategory(node.categoryId!);
            }
        };

        const pillHeight = isNet ? PILL_LABEL_HEIGHT : PARENT_PILL_HEIGHT;
        const labelBoxHeight = isPill ? pillHeight : LABEL_HEIGHT;
        // Centred on its bar, except at the very edges: the first and last
        // node of a column sit flush against the canvas, so half of a label
        // taller than the bar would hang outside it and get clipped. "Other"
        // is always the last of its column, and expanding it makes its label
        // a pill, so that is the rule rather than the exception.
        const labelY = Math.min(
            Math.max(0, y + nodeHeight / 2 - labelBoxHeight / 2),
            chartHeight - labelBoxHeight,
        );
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

        // The chevron points the way the subcategory column will open: income
        // expands to the left (`<`, then `>` once open), expense to the right
        // (`>`, then a downward `v` once open).
        const ChevronIcon =
            node.kind === 'income'
                ? node.expanded
                    ? ChevronRight
                    : ChevronLeft
                : node.expanded
                  ? ChevronDown
                  : ChevronRight;

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
                {node.offset ? (
                    <rect
                        x={x}
                        y={y + nodeHeight / 2 - OFFSET_MARKER_HEIGHT / 2}
                        width={width}
                        height={OFFSET_MARKER_HEIGHT}
                        rx={2}
                        fill="none"
                        stroke={node.color}
                        strokeDasharray="2 2"
                        strokeOpacity={0.6}
                    />
                ) : (
                    <rect
                        x={x}
                        y={y}
                        width={width}
                        height={nodeHeight}
                        rx={2}
                        fill={node.color}
                        fillOpacity={0.9}
                    />
                )}
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
                            {/* A share gives no amount away, so privacy mode
                                leaves it readable — it is what keeps the chart
                                useful with the numbers masked. */}
                            {!isNet && node.share && ` · ${node.share}`}
                        </span>
                        {isNet && node.share && (
                            <span className="text-[11px] text-muted-foreground">
                                {__(':percent of income', {
                                    percent: node.share,
                                })}
                            </span>
                        )}
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
        const curve = `M${sourceX},${sourceY} C${sourceControlX},${sourceY} ${targetControlX},${targetY} ${targetX},${targetY}`;
        const offsetNode = [payload.source, payload.target].find(
            (node) => node.offset,
        );

        // An offset has no flow to draw, only a thin dashed connector, so it
        // still reads as part of the parent it was netted into.
        if (offsetNode) {
            return (
                <path
                    key={`link-${index}`}
                    d={curve}
                    fill="none"
                    stroke={offsetNode.color}
                    strokeWidth={OFFSET_LINK_WIDTH}
                    strokeDasharray="4 3"
                    strokeOpacity={0.6}
                />
            );
        }

        const kind =
            payload.source.kind === 'center'
                ? payload.target.kind
                : payload.source.kind;
        const stroke =
            kind === 'income' ? cashflowIncomeColor : cashflowExpenseColor;

        return (
            <path
                key={`link-${index}`}
                d={curve}
                fill="none"
                stroke={stroke}
                strokeWidth={Math.max(1, linkWidth)}
                strokeOpacity={0.4}
            />
        );
    };

    return (
        <div
            className={cn('w-full overflow-x-auto', className)}
            data-testid="cashflow-sankey"
        >
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

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Separator } from '@/components/ui/separator';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { SankeyCategory, SankeyData } from '@/hooks/use-cashflow-data';
import { useLocale } from '@/hooks/use-locale';
import {
    calculatePercentage,
    GroupedCategory,
    groupSmallCategories,
} from '@/lib/sankey-utils';
import { cn } from '@/lib/utils';
import { Category } from '@/types/category';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { format } from 'date-fns';
import { ChevronsRight } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface SankeyChartProps {
    data: SankeyData;
    height?: number;
    className?: string;
    currency?: string;
    groupingThreshold?: number;
    period?: { from: Date; to: Date };
}

type ColumnKey =
    | 'incomeChild'
    | 'income'
    | 'center'
    | 'expense'
    | 'expenseChild';

interface NodeData {
    id: string;
    label: string;
    value: number;
    color: string;
    y: number;
    height: number;
    column: ColumnKey;
    columnFraction: number;
    category?: Category;
    hasChildren?: boolean;
    expandable?: boolean;
}

interface LinkData {
    source: string;
    target: string;
    value: number;
    sourceY: number;
    targetY: number;
    sourceHeight: number;
    targetHeight: number;
    kind: 'income' | 'expense';
}

const NODE_WIDTH = 8;
const NODE_PADDING = 6;
const MIN_NODE_HEIGHT = 28;
const MIN_RENDERED_WIDTH = 400;
const MAX_RENDERED_WIDTH = 800;
const LABEL_BLOCK_HEIGHT = 24;
const LABEL_GAP = 6;
const LABEL_PAD = 4;
const LABEL_CENTER_WIDTH = 90;

interface OtherCategoriesBreakdownProps {
    categories: SankeyCategory[];
    total: number;
    currency: string;
    grandTotal: number;
    locale: string;
    isPrivacyModeEnabled: boolean;
}

function OtherCategoriesBreakdown({
    categories,
    total,
    currency,
    grandTotal,
    locale,
    isPrivacyModeEnabled,
}: OtherCategoriesBreakdownProps) {
    const maskIfPrivate = (value: number) => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
    };

    return (
        <div className="w-64">
            <div className="space-y-3">
                <div>
                    <h4 className="text-sm font-medium">
                        {__('Other Categories (')}
                        {categories.length})
                    </h4>
                    <p className="text-xs text-muted-foreground">
                        {__('Categories below 5% of total')}
                    </p>
                </div>

                <div className="max-h-60 space-y-1.5 overflow-y-auto">
                    {categories.map((item) => {
                        const percentage = calculatePercentage(
                            item.amount,
                            grandTotal,
                        );
                        return (
                            <div
                                key={item.category_id}
                                className="flex items-center justify-between gap-3 text-xs"
                            >
                                <div className="flex items-center gap-2 truncate">
                                    <div
                                        className="size-2 shrink-0 rounded-full"
                                        style={{
                                            backgroundColor:
                                                item.category.color ||
                                                'var(--color-chart-4)',
                                        }}
                                    />

                                    <span className="truncate">
                                        {item.category.name}
                                    </span>
                                </div>
                                <div className="flex shrink-0 items-center gap-2">
                                    <span className="font-medium">
                                        {maskIfPrivate(item.amount)}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {percentage.toFixed(1)}%
                                    </span>
                                </div>
                            </div>
                        );
                    })}
                </div>

                <Separator />

                <div className="flex items-center justify-between text-sm font-medium">
                    <span>{__('Total')}</span>
                    <span>{maskIfPrivate(total)}</span>
                </div>
            </div>
        </div>
    );
}

export function SankeyChart({
    data,
    height = 400,
    className,
    currency = 'USD',
    groupingThreshold = 0.03,
    period,
}: SankeyChartProps) {
    const [hoveredNode, setHoveredNode] = useState<string | null>(null);
    const [hoveredLink, setHoveredLink] = useState<string | null>(null);
    const [renderedWidth, setRenderedWidth] = useState(MAX_RENDERED_WIDTH);
    const [expandedIds, setExpandedIds] = useState<Set<string>>(new Set());
    const [childrenById, setChildrenById] = useState<
        Record<string, SankeyData>
    >({});
    const containerRef = useRef<HTMLDivElement>(null);
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();

    const periodKey = period
        ? `${period.from.getTime()}-${period.to.getTime()}`
        : '';

    const maskIfPrivate = (value: number) => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
    };

    const toggleExpand = (categoryId: string) => {
        setExpandedIds((previous) => {
            if (previous.has(categoryId)) {
                return new Set<string>();
            }

            return new Set([categoryId]);
        });
    };

    useEffect(() => {
        const container = containerRef.current;

        if (!container) {
            return;
        }

        const updateWidth = () => {
            setRenderedWidth(
                Math.round(
                    Math.min(
                        MAX_RENDERED_WIDTH,
                        Math.max(MIN_RENDERED_WIDTH, container.clientWidth),
                    ),
                ),
            );
        };

        updateWidth();

        if (typeof ResizeObserver === 'undefined') {
            window.addEventListener('resize', updateWidth);

            return () => window.removeEventListener('resize', updateWidth);
        }

        const observer = new ResizeObserver(updateWidth);
        observer.observe(container);

        return () => observer.disconnect();
    }, []);

    // Changing the period invalidates any expanded subcategories.
    useEffect(() => {
        setExpandedIds(new Set());
        setChildrenById({});
    }, [periodKey]);

    // Lazily fetch the children of each newly expanded category.
    useEffect(() => {
        if (!period) {
            return;
        }

        const missing = [...expandedIds].filter((id) => !(id in childrenById));

        if (missing.length === 0) {
            return;
        }

        const from = format(period.from, 'yyyy-MM-dd');
        const to = format(period.to, 'yyyy-MM-dd');
        let cancelled = false;

        missing.forEach(async (id) => {
            try {
                const response = await fetch(
                    `/api/cashflow/sankey?from=${from}&to=${to}&parent=${id}`,
                );
                const json: SankeyData = await response.json();

                if (!cancelled) {
                    setChildrenById((previous) => ({
                        ...previous,
                        [id]: json,
                    }));
                }
            } catch (error) {
                console.error('Failed to fetch subcategories:', error);
            }
        });

        return () => {
            cancelled = true;
        };
    }, [expandedIds, childrenById, period, periodKey]);

    const { nodes, links, isEmpty, otherGroups, viewBoxTop, viewBoxHeight } =
        useMemo(() => {
            const {
                income_categories,
                expense_categories,
                total_income,
                total_expense,
            } = data;

            if (total_income === 0 && total_expense === 0) {
                return {
                    nodes: [] as NodeData[],
                    links: [] as LinkData[],
                    isEmpty: true,
                    otherGroups: {} as Record<string, GroupedCategory>,
                    viewBoxTop: 0,
                    viewBoxHeight: height,
                };
            }

            const otherGroupsMap: Record<string, GroupedCategory> = {};
            const availableHeight = height - 40; // padding
            const maxTotal = Math.max(total_income, total_expense);

            // Income parent nodes (left column)
            const groupedIncome = groupSmallCategories(
                income_categories,
                total_income,
                groupingThreshold,
            );

            let incomeY = 20;
            const incomeNodes: NodeData[] = groupedIncome.main.map((item) => {
                const nodeHeight = Math.max(
                    MIN_NODE_HEIGHT,
                    (item.amount / maxTotal) * availableHeight * 0.5,
                );
                const node: NodeData = {
                    id: `income-${item.category_id}`,
                    label: item.category.name,
                    value: item.amount,
                    color: item.category.color || 'var(--color-chart-2)',
                    y: incomeY,
                    height: nodeHeight,
                    column: 'income',
                    columnFraction: 0,
                    category: item.category,
                    hasChildren: item.has_children,
                    expandable: !!item.has_children,
                };
                incomeY += nodeHeight + NODE_PADDING;
                return node;
            });

            if (groupedIncome.other) {
                const nodeHeight = Math.max(
                    MIN_NODE_HEIGHT,
                    (groupedIncome.other.total / maxTotal) *
                        availableHeight *
                        0.5,
                );
                incomeNodes.push({
                    id: 'income-other',
                    label: __('Other'),
                    value: groupedIncome.other.total,
                    color: 'var(--color-muted)',
                    y: incomeY,
                    height: nodeHeight,
                    column: 'income',
                    columnFraction: 0,
                });
                otherGroupsMap['income-other'] = groupedIncome.other;
                incomeY += nodeHeight + NODE_PADDING;
            }

            // Center node (total cashflow)
            const centerHeight = Math.max(
                MIN_NODE_HEIGHT * 1.5,
                (Math.max(total_income, total_expense) / maxTotal) *
                    availableHeight *
                    0.6,
            );
            const centerY = (height - centerHeight) / 2;
            const centerNode: NodeData = {
                id: 'center',
                label: __('Cashflow'),
                value: total_income - total_expense,
                color: 'var(--color-chart-1)',
                y: centerY,
                height: centerHeight,
                column: 'center',
                columnFraction: 0,
            };

            // Expense parent nodes (right column)
            const groupedExpense = groupSmallCategories(
                expense_categories,
                total_expense,
                groupingThreshold,
            );

            let expenseY = 20;
            const expenseNodes: NodeData[] = groupedExpense.main.map((item) => {
                const nodeHeight = Math.max(
                    MIN_NODE_HEIGHT,
                    (item.amount / maxTotal) * availableHeight * 0.5,
                );
                const node: NodeData = {
                    id: `expense-${item.category_id}`,
                    label: item.category.name,
                    value: item.amount,
                    color: item.category.color || 'var(--color-chart-3)',
                    y: expenseY,
                    height: nodeHeight,
                    column: 'expense',
                    columnFraction: 0,
                    category: item.category,
                    hasChildren: item.has_children,
                    expandable: !!item.has_children,
                };
                expenseY += nodeHeight + NODE_PADDING;
                return node;
            });

            if (groupedExpense.other) {
                const nodeHeight = Math.max(
                    MIN_NODE_HEIGHT,
                    (groupedExpense.other.total / maxTotal) *
                        availableHeight *
                        0.5,
                );
                expenseNodes.push({
                    id: 'expense-other',
                    label: __('Other'),
                    value: groupedExpense.other.total,
                    color: 'var(--color-muted)',
                    y: expenseY,
                    height: nodeHeight,
                    column: 'expense',
                    columnFraction: 0,
                });
                otherGroupsMap['expense-other'] = groupedExpense.other;
                expenseY += nodeHeight + NODE_PADDING;
            }

            // Resolve which expanded parents actually have loaded children.
            const sortByAmount = (
                categories: SankeyCategory[],
            ): SankeyCategory[] =>
                [...categories].sort((a, b) => b.amount - a.amount);

            const incomeChildren: Record<string, SankeyCategory[]> = {};
            incomeNodes.forEach((node) => {
                if (node.category && expandedIds.has(node.category.id)) {
                    const kids =
                        childrenById[node.category.id]?.income_categories;

                    if (kids && kids.length > 0) {
                        incomeChildren[node.id] = sortByAmount(kids);
                    }
                }
            });

            const expenseChildren: Record<string, SankeyCategory[]> = {};
            expenseNodes.forEach((node) => {
                if (node.category && expandedIds.has(node.category.id)) {
                    const kids =
                        childrenById[node.category.id]?.expense_categories;

                    if (kids && kids.length > 0) {
                        expenseChildren[node.id] = sortByAmount(kids);
                    }
                }
            });

            const hasIncomeChildColumn = Object.keys(incomeChildren).length > 0;
            const hasExpenseChildColumn =
                Object.keys(expenseChildren).length > 0;

            // Lay out the active columns left-to-right.
            const columns: ColumnKey[] = [];
            if (hasIncomeChildColumn) {
                columns.push('incomeChild');
            }
            columns.push('income', 'center', 'expense');
            if (hasExpenseChildColumn) {
                columns.push('expenseChild');
            }

            const pad = columns.length <= 3 ? 0.25 : 0.12;
            const fractionFor = (index: number): number =>
                columns.length <= 1
                    ? 0.5
                    : pad + (index / (columns.length - 1)) * (1 - 2 * pad);
            const fractionByColumn = {} as Record<ColumnKey, number>;
            columns.forEach((column, index) => {
                fractionByColumn[column] = fractionFor(index);
            });

            incomeNodes.forEach((node) => {
                node.columnFraction = fractionByColumn.income;
            });
            expenseNodes.forEach((node) => {
                node.columnFraction = fractionByColumn.expense;
            });
            centerNode.columnFraction = fractionByColumn.center;

            const linkList: LinkData[] = [];

            // Income parents -> center
            let incomeLinkY = centerY;
            incomeNodes.forEach((incomeNode) => {
                const linkHeight =
                    total_income > 0
                        ? (incomeNode.value / total_income) * centerHeight
                        : 0;
                linkList.push({
                    source: incomeNode.id,
                    target: 'center',
                    value: incomeNode.value,
                    sourceY: incomeNode.y + incomeNode.height / 2,
                    targetY: incomeLinkY + linkHeight / 2,
                    sourceHeight: incomeNode.height,
                    targetHeight: linkHeight,
                    kind: 'income',
                });
                incomeLinkY += linkHeight;
            });

            // Center -> expense parents
            let expenseLinkY = centerY;
            expenseNodes.forEach((expenseNode) => {
                const linkHeight =
                    total_expense > 0
                        ? (expenseNode.value / total_expense) * centerHeight
                        : 0;
                linkList.push({
                    source: 'center',
                    target: expenseNode.id,
                    value: expenseNode.value,
                    sourceY: expenseLinkY + linkHeight / 2,
                    targetY: expenseNode.y + expenseNode.height / 2,
                    sourceHeight: linkHeight,
                    targetHeight: expenseNode.height,
                    kind: 'expense',
                });
                expenseLinkY += linkHeight;
            });

            // Child nodes stack within their parent's vertical band so the parent
            // visibly splits into its subcategories.
            const childNodes: NodeData[] = [];
            const buildChildren = (
                parents: NodeData[],
                childrenByParent: Record<string, SankeyCategory[]>,
                childColumn: ColumnKey,
                kind: 'income' | 'expense',
            ) => {
                parents.forEach((parent) => {
                    const kids = childrenByParent[parent.id];

                    if (!kids) {
                        return;
                    }

                    const childFraction = fractionByColumn[childColumn];
                    const kidsSum = kids.reduce(
                        (sum, kid) => sum + kid.amount,
                        0,
                    );

                    if (kidsSum <= 0) {
                        return;
                    }

                    // Size each child like a top-level node (same minimum height
                    // and gap), then center the stack on the parent so the links
                    // fan out from the parent's proportional slices.
                    const childHeights = kids.map((kid) =>
                        Math.max(
                            MIN_NODE_HEIGHT,
                            (kid.amount / maxTotal) * availableHeight * 0.5,
                        ),
                    );
                    const stackHeight =
                        childHeights.reduce((sum, h) => sum + h, 0) +
                        NODE_PADDING * (kids.length - 1);
                    let childCursor =
                        parent.y + parent.height / 2 - stackHeight / 2;
                    let parentCursor = parent.y;

                    kids.forEach((kid, index) => {
                        const childHeight = childHeights[index];
                        const parentSlice =
                            (kid.amount / kidsSum) * parent.height;
                        const node: NodeData = {
                            id: `${childColumn}-${parent.id}-${index}`,
                            label: kid.category.name,
                            value: kid.amount,
                            color:
                                kid.category.color ||
                                (kind === 'income'
                                    ? 'var(--color-chart-2)'
                                    : 'var(--color-chart-3)'),
                            y: childCursor,
                            height: childHeight,
                            column: childColumn,
                            columnFraction: childFraction,
                            category: kid.category,
                            hasChildren: kid.has_children,
                            expandable: false,
                        };
                        childNodes.push(node);

                        if (kind === 'income') {
                            linkList.push({
                                source: node.id,
                                target: parent.id,
                                value: kid.amount,
                                sourceY: node.y + childHeight / 2,
                                targetY: parentCursor + parentSlice / 2,
                                sourceHeight: childHeight,
                                targetHeight: parentSlice,
                                kind: 'income',
                            });
                        } else {
                            linkList.push({
                                source: parent.id,
                                target: node.id,
                                value: kid.amount,
                                sourceY: parentCursor + parentSlice / 2,
                                targetY: node.y + childHeight / 2,
                                sourceHeight: parentSlice,
                                targetHeight: childHeight,
                                kind: 'expense',
                            });
                        }

                        childCursor += childHeight + NODE_PADDING;
                        parentCursor += parentSlice;
                    });
                });
            };

            buildChildren(incomeNodes, incomeChildren, 'incomeChild', 'income');
            buildChildren(
                expenseNodes,
                expenseChildren,
                'expenseChild',
                'expense',
            );

            // Expanded child stacks can be taller than their parent band and reach
            // above the top or below the bottom of the base canvas. Grow the
            // viewBox to fit so nothing gets clipped.
            const allNodes = [
                ...incomeNodes,
                centerNode,
                ...expenseNodes,
                ...childNodes,
            ];
            const contentTop = Math.min(...allNodes.map((node) => node.y));
            const contentBottom = Math.max(
                ...allNodes.map((node) => node.y + node.height),
            );
            const viewBoxTop = Math.min(0, contentTop - 20);
            const viewBoxBottom = Math.max(height, contentBottom + 20);

            return {
                nodes: allNodes,
                links: linkList,
                isEmpty: false,
                otherGroups: otherGroupsMap,
                viewBoxTop,
                viewBoxHeight: viewBoxBottom - viewBoxTop,
            };
        }, [data, height, groupingThreshold, expandedIds, childrenById]);

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

    const width = renderedWidth;

    const isLeftAligned = (column: ColumnKey): boolean =>
        column === 'incomeChild' || column === 'income';
    const isRightAligned = (column: ColumnKey): boolean =>
        column === 'expense' || column === 'expenseChild';

    return (
        <div
            ref={containerRef}
            className={cn('w-full overflow-x-auto', className)}
        >
            <svg
                viewBox={`0 ${viewBoxTop} ${width} ${viewBoxHeight}`}
                className="mx-auto block"
                style={{ width: renderedWidth, minWidth: MIN_RENDERED_WIDTH }}
                preserveAspectRatio="xMidYMid meet"
            >
                {/* Links */}
                <g className="links">
                    {links.map((link) => {
                        const sourceNode = nodes.find(
                            (n) => n.id === link.source,
                        );
                        const targetNode = nodes.find(
                            (n) => n.id === link.target,
                        );
                        if (!sourceNode || !targetNode) return null;

                        const sourceX =
                            sourceNode.columnFraction * width + NODE_WIDTH / 2;
                        const targetX =
                            targetNode.columnFraction * width - NODE_WIDTH / 2;

                        const linkId = `${link.source}-${link.target}`;
                        const isHovered =
                            hoveredLink === linkId ||
                            hoveredNode === link.source ||
                            hoveredNode === link.target;

                        // Create a curved path
                        const path = `
                            M ${sourceX} ${link.sourceY - link.sourceHeight / 2}
                            C ${(sourceX + targetX) / 2} ${link.sourceY - link.sourceHeight / 2},
                              ${(sourceX + targetX) / 2} ${link.targetY - link.targetHeight / 2},
                              ${targetX} ${link.targetY - link.targetHeight / 2}
                            L ${targetX} ${link.targetY + link.targetHeight / 2}
                            C ${(sourceX + targetX) / 2} ${link.targetY + link.targetHeight / 2},
                              ${(sourceX + targetX) / 2} ${link.sourceY + link.sourceHeight / 2},
                              ${sourceX} ${link.sourceY + link.sourceHeight / 2}
                            Z
                        `;

                        return (
                            <path
                                key={linkId}
                                d={path}
                                fill={
                                    link.kind === 'income'
                                        ? 'var(--color-chart-2)'
                                        : 'var(--color-chart-3)'
                                }
                                fillOpacity={isHovered ? 0.6 : 0.3}
                                className="transition-all duration-200"
                                onMouseEnter={() => setHoveredLink(linkId)}
                                onMouseLeave={() => setHoveredLink(null)}
                            />
                        );
                    })}
                </g>

                {/* Nodes */}
                <g className="nodes">
                    {nodes.map((node) => {
                        const x = node.columnFraction * width - NODE_WIDTH / 2;
                        const isHovered = hoveredNode === node.id;
                        const isOtherNode = node.id.endsWith('-other');
                        const otherGroup = isOtherNode
                            ? otherGroups[node.id]
                            : null;
                        const categoryUrl =
                            node.category && period
                                ? transactionsIndex({
                                      query: {
                                          category_ids: node.category.id,
                                          date_from: format(
                                              period.from,
                                              'yyyy-MM-dd',
                                          ),
                                          date_to: format(
                                              period.to,
                                              'yyyy-MM-dd',
                                          ),
                                      },
                                  }).url
                                : null;
                        const canExpand =
                            !isOtherNode &&
                            !!node.expandable &&
                            !!node.category &&
                            !!period;
                        const isExpanded =
                            !!node.category &&
                            expandedIds.has(node.category.id);
                        const isNavigable =
                            !isOtherNode && !canExpand && categoryUrl !== null;
                        const isInteractive = canExpand || isNavigable;

                        const activate = () => {
                            if (canExpand && node.category) {
                                toggleExpand(node.category.id);
                                return;
                            }

                            if (categoryUrl) {
                                router.visit(categoryUrl);
                            }
                        };

                        // The label/amount block is rendered as real HTML in a
                        // foreignObject so flexbox handles spacing, vertical
                        // centering, and ellipsis truncation reliably.
                        const rightAligned = isRightAligned(node.column);
                        const leftAligned = isLeftAligned(node.column);
                        const labelX = rightAligned
                            ? x + NODE_WIDTH + LABEL_GAP
                            : leftAligned
                              ? LABEL_PAD
                              : x + NODE_WIDTH / 2 - LABEL_CENTER_WIDTH / 2;
                        const labelWidth = rightAligned
                            ? Math.max(0, width - labelX - LABEL_PAD)
                            : leftAligned
                              ? Math.max(0, x - LABEL_GAP - LABEL_PAD)
                              : LABEL_CENTER_WIDTH;
                        const labelY =
                            node.y + node.height / 2 - LABEL_BLOCK_HEIGHT / 2;
                        const iconRotationClass = isExpanded
                            ? node.column === 'income'
                                ? ''
                                : 'rotate-180'
                            : node.column === 'income'
                              ? 'rotate-180'
                              : '';

                        const nodeContent = (
                            <g
                                key={node.id}
                                onMouseEnter={() => setHoveredNode(node.id)}
                                onMouseLeave={() => setHoveredNode(null)}
                                onClick={() => {
                                    if (isInteractive) {
                                        activate();
                                    }
                                }}
                                onKeyDown={(event) => {
                                    if (!isInteractive) {
                                        return;
                                    }

                                    if (
                                        event.key === 'Enter' ||
                                        event.key === ' '
                                    ) {
                                        event.preventDefault();
                                        activate();
                                    }
                                }}
                                role={
                                    canExpand
                                        ? 'button'
                                        : isNavigable
                                          ? 'link'
                                          : undefined
                                }
                                tabIndex={isInteractive ? 0 : undefined}
                                aria-label={
                                    canExpand
                                        ? isExpanded
                                            ? `Collapse ${node.label}`
                                            : `Expand ${node.label}`
                                        : isNavigable
                                          ? `View ${node.label} transactions`
                                          : undefined
                                }
                                className={cn(
                                    'transition-all duration-200',
                                    isOtherNode && 'cursor-pointer',
                                    isInteractive && 'cursor-pointer',
                                    !isOtherNode &&
                                        !isInteractive &&
                                        'cursor-default',
                                )}
                            >
                                <rect
                                    x={x}
                                    y={node.y}
                                    width={NODE_WIDTH}
                                    height={node.height}
                                    rx={2}
                                    fill={
                                        node.id === 'center'
                                            ? 'var(--color-chart-1)'
                                            : node.color
                                    }
                                    fillOpacity={
                                        isOtherNode
                                            ? isHovered
                                                ? 1
                                                : 0.6
                                            : isHovered
                                              ? 1
                                              : 0.8
                                    }
                                    stroke={
                                        isOtherNode ? 'var(--border)' : 'none'
                                    }
                                    strokeWidth={isOtherNode ? 1 : 0}
                                    className="transition-all duration-200"
                                />

                                {/* Label + amount */}
                                <foreignObject
                                    x={labelX}
                                    y={labelY}
                                    width={labelWidth}
                                    height={LABEL_BLOCK_HEIGHT}
                                    className="overflow-visible"
                                >
                                    <div
                                        className={cn(
                                            'flex h-full flex-col justify-center gap-0.5 leading-tight',
                                            rightAligned &&
                                                'items-start text-left',
                                            leftAligned &&
                                                'items-end text-right',
                                            !rightAligned &&
                                                !leftAligned &&
                                                'items-center text-center',
                                        )}
                                    >
                                        <div
                                            className={cn(
                                                'flex max-w-full items-center gap-2',
                                                node.column === 'income' &&
                                                    'flex-row-reverse',
                                            )}
                                        >
                                            <span
                                                title={node.label}
                                                className="min-w-0 truncate text-[11px] font-medium text-foreground"
                                            >
                                                {node.label}
                                                {isOtherNode && (
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        ⋯
                                                    </span>
                                                )}
                                            </span>
                                            {canExpand && (
                                                <ChevronsRight
                                                    aria-hidden="true"
                                                    className={cn(
                                                        'size-3 shrink-0 text-muted-foreground',
                                                        iconRotationClass,
                                                    )}
                                                />
                                            )}
                                        </div>
                                        <span className="text-[11px] text-muted-foreground">
                                            {maskIfPrivate(node.value)}
                                        </span>
                                    </div>
                                </foreignObject>
                            </g>
                        );

                        // Wrap "Other" nodes in Popover
                        if (isOtherNode && otherGroup) {
                            const grandTotal = node.id.startsWith('income-')
                                ? data.total_income
                                : data.total_expense;

                            return (
                                <Popover key={node.id}>
                                    <PopoverTrigger
                                        asChild
                                        aria-label={`View ${otherGroup.categories.length} grouped categories totaling ${maskIfPrivate(otherGroup.total)}`}
                                    >
                                        {nodeContent}
                                    </PopoverTrigger>
                                    <PopoverContent
                                        align={
                                            node.id.startsWith('income-')
                                                ? 'start'
                                                : 'end'
                                        }
                                        side="top"
                                        className="p-4"
                                    >
                                        <OtherCategoriesBreakdown
                                            categories={otherGroup.categories}
                                            total={otherGroup.total}
                                            currency={currency}
                                            grandTotal={grandTotal}
                                            locale={locale}
                                            isPrivacyModeEnabled={
                                                isPrivacyModeEnabled
                                            }
                                        />
                                    </PopoverContent>
                                </Popover>
                            );
                        }

                        return nodeContent;
                    })}
                </g>
            </svg>
        </div>
    );
}

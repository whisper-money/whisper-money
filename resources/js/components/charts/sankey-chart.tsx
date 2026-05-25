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
import { useEffect, useMemo, useRef, useState } from 'react';

interface SankeyChartProps {
    data: SankeyData;
    height?: number;
    className?: string;
    currency?: string;
    groupingThreshold?: number;
    period?: { from: Date; to: Date };
}

interface NodeData {
    id: string;
    label: string;
    value: number;
    color: string;
    y: number;
    height: number;
    column: 0 | 1 | 2;
    category?: Category;
}

interface LinkData {
    source: string;
    target: string;
    value: number;
    sourceY: number;
    targetY: number;
    sourceHeight: number;
    targetHeight: number;
}

const COLUMN_POSITIONS = [0.25, 0.5, 0.75];
const NODE_WIDTH = 8;
const NODE_PADDING = 6;
const MIN_NODE_HEIGHT = 20;
const MIN_RENDERED_WIDTH = 400;
const MAX_RENDERED_WIDTH = 800;

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
    const containerRef = useRef<HTMLDivElement>(null);
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();

    const maskIfPrivate = (value: number) => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
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

    const { nodes, links, isEmpty, otherGroups } = useMemo(() => {
        const {
            income_categories,
            expense_categories,
            total_income,
            total_expense,
        } = data;

        if (total_income === 0 && total_expense === 0) {
            return {
                nodes: [],
                links: [],
                isEmpty: true,
                otherGroups: {},
            };
        }

        const nodeMap: Record<string, NodeData> = {};
        const linkList: LinkData[] = [];
        const otherGroupsMap: Record<string, GroupedCategory> = {};

        // Calculate available height for nodes
        const availableHeight = height - 40; // padding
        const maxTotal = Math.max(total_income, total_expense);

        // Group income categories
        const groupedIncome = groupSmallCategories(
            income_categories,
            total_income,
            groupingThreshold,
        );

        // Create income nodes (left column)
        let incomeY = 20;
        const incomeNodes = groupedIncome.main.map((item) => {
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
                column: 0,
                category: item.category,
            };
            incomeY += nodeHeight + NODE_PADDING;
            return node;
        });

        // Add "Other" income node if needed
        if (groupedIncome.other) {
            const nodeHeight = Math.max(
                MIN_NODE_HEIGHT,
                (groupedIncome.other.total / maxTotal) * availableHeight * 0.5,
            );
            const otherNode: NodeData = {
                id: 'income-other',
                label: __('Other'),
                value: groupedIncome.other.total,
                color: 'var(--color-muted)',
                y: incomeY,
                height: nodeHeight,
                column: 0,
            };
            incomeNodes.push(otherNode);
            otherGroupsMap['income-other'] = groupedIncome.other;
            incomeY += nodeHeight + NODE_PADDING;
        }

        // Create center node (total cashflow)
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
            column: 1,
        };

        // Group expense categories
        const groupedExpense = groupSmallCategories(
            expense_categories,
            total_expense,
            groupingThreshold,
        );

        // Create expense nodes (right column)
        let expenseY = 20;
        const expenseNodes = groupedExpense.main.map((item) => {
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
                column: 2,
                category: item.category,
            };
            expenseY += nodeHeight + NODE_PADDING;
            return node;
        });

        // Add "Other" expense node if needed
        if (groupedExpense.other) {
            const nodeHeight = Math.max(
                MIN_NODE_HEIGHT,
                (groupedExpense.other.total / maxTotal) * availableHeight * 0.5,
            );
            const otherNode: NodeData = {
                id: 'expense-other',
                label: __('Other'),
                value: groupedExpense.other.total,
                color: 'var(--color-muted)',
                y: expenseY,
                height: nodeHeight,
                column: 2,
            };
            expenseNodes.push(otherNode);
            otherGroupsMap['expense-other'] = groupedExpense.other;
            expenseY += nodeHeight + NODE_PADDING;
        }

        // Add all nodes to map
        incomeNodes.forEach((n) => (nodeMap[n.id] = n));
        nodeMap[centerNode.id] = centerNode;
        expenseNodes.forEach((n) => (nodeMap[n.id] = n));

        // Create links from income to center
        let incomeLinkY = centerY;
        incomeNodes.forEach((incomeNode) => {
            const linkHeight = (incomeNode.value / total_income) * centerHeight;
            linkList.push({
                source: incomeNode.id,
                target: 'center',
                value: incomeNode.value,
                sourceY: incomeNode.y + incomeNode.height / 2,
                targetY: incomeLinkY + linkHeight / 2,
                sourceHeight: incomeNode.height,
                targetHeight: linkHeight,
            });
            incomeLinkY += linkHeight;
        });

        // Create links from center to expenses
        let expenseLinkY = centerY;
        expenseNodes.forEach((expenseNode) => {
            const linkHeight =
                (expenseNode.value / total_expense) * centerHeight;
            linkList.push({
                source: 'center',
                target: expenseNode.id,
                value: expenseNode.value,
                sourceY: expenseLinkY + linkHeight / 2,
                targetY: expenseNode.y + expenseNode.height / 2,
                sourceHeight: linkHeight,
                targetHeight: expenseNode.height,
            });
            expenseLinkY += linkHeight;
        });

        return {
            nodes: Object.values(nodeMap),
            links: linkList,
            isEmpty: false,
            otherGroups: otherGroupsMap,
        };
    }, [data, height, groupingThreshold]);

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

    return (
        <div
            ref={containerRef}
            className={cn('w-full overflow-x-auto', className)}
        >
            <svg
                viewBox={`0 0 ${width} ${height}`}
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
                            COLUMN_POSITIONS[sourceNode.column] * width +
                            NODE_WIDTH / 2;
                        const targetX =
                            COLUMN_POSITIONS[targetNode.column] * width -
                            NODE_WIDTH / 2;

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
                                    sourceNode.column === 0
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
                        const x =
                            COLUMN_POSITIONS[node.column] * width -
                            NODE_WIDTH / 2;
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
                        const isNavigable =
                            !isOtherNode && categoryUrl !== null;

                        const nodeContent = (
                            <g
                                key={node.id}
                                onMouseEnter={() => setHoveredNode(node.id)}
                                onMouseLeave={() => setHoveredNode(null)}
                                onClick={() => {
                                    if (categoryUrl) {
                                        router.visit(categoryUrl);
                                    }
                                }}
                                onKeyDown={(event) => {
                                    if (!categoryUrl) {
                                        return;
                                    }

                                    if (
                                        event.key === 'Enter' ||
                                        event.key === ' '
                                    ) {
                                        event.preventDefault();
                                        router.visit(categoryUrl);
                                    }
                                }}
                                role={isNavigable ? 'link' : undefined}
                                tabIndex={isNavigable ? 0 : undefined}
                                aria-label={
                                    isNavigable
                                        ? `View ${node.label} transactions`
                                        : undefined
                                }
                                className={cn(
                                    'transition-all duration-200',
                                    isOtherNode && 'cursor-pointer',
                                    isNavigable && 'cursor-pointer',
                                    !isOtherNode &&
                                        !isNavigable &&
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

                                {/* Label */}
                                <text
                                    x={
                                        node.column === 0
                                            ? x - 6
                                            : node.column === 2
                                              ? x + NODE_WIDTH + 6
                                              : x + NODE_WIDTH / 2
                                    }
                                    y={node.y + node.height / 2 - 6}
                                    textAnchor={
                                        node.column === 0
                                            ? 'end'
                                            : node.column === 2
                                              ? 'start'
                                              : 'middle'
                                    }
                                    dominantBaseline="middle"
                                    className="fill-foreground text-[11px] font-medium"
                                >
                                    {node.label}
                                    {isOtherNode && (
                                        <tspan className="fill-muted-foreground">
                                            {' '}
                                            ⋯
                                        </tspan>
                                    )}
                                </text>
                                {/* Amount */}
                                <text
                                    x={
                                        node.column === 0
                                            ? x - 6
                                            : node.column === 2
                                              ? x + NODE_WIDTH + 6
                                              : x + NODE_WIDTH / 2
                                    }
                                    y={node.y + node.height / 2 + 6}
                                    textAnchor={
                                        node.column === 0
                                            ? 'end'
                                            : node.column === 2
                                              ? 'start'
                                              : 'middle'
                                    }
                                    dominantBaseline="middle"
                                    className="fill-muted-foreground text-[11px]"
                                >
                                    {maskIfPrivate(node.value)}
                                </text>
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
                                            node.column === 0 ? 'start' : 'end'
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

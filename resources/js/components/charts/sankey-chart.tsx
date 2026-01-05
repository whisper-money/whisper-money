import { SankeyData } from '@/hooks/use-cashflow-data';
import { cn } from '@/lib/utils';
import { Category } from '@/types/category';
import { useMemo, useState } from 'react';

interface SankeyChartProps {
    data: SankeyData;
    height?: number;
    className?: string;
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
    height: number;
}

const COLUMN_POSITIONS = [0.25, 0.5, 0.75];
const NODE_WIDTH = 12;
const NODE_PADDING = 6;
const MIN_NODE_HEIGHT = 20;

function formatAmount(amountInCents: number): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amountInCents / 100);
}

export function SankeyChart({
    data,
    height = 400,
    className,
}: SankeyChartProps) {
    const [hoveredNode, setHoveredNode] = useState<string | null>(null);
    const [hoveredLink, setHoveredLink] = useState<string | null>(null);

    const { nodes, links, isEmpty } = useMemo(() => {
        const {
            income_categories,
            expense_categories,
            total_income,
            total_expense,
        } = data;

        if (total_income === 0 && total_expense === 0) {
            return { nodes: [], links: [], isEmpty: true };
        }

        const nodeMap: Record<string, NodeData> = {};
        const linkList: LinkData[] = [];

        // Calculate available height for nodes
        const availableHeight = height - 40; // padding
        const maxTotal = Math.max(total_income, total_expense);

        // Create income nodes (left column)
        let incomeY = 20;
        const incomeNodes = income_categories
            .sort((a, b) => b.amount - a.amount)
            .map((item) => {
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
            label: 'Cashflow',
            value: total_income - total_expense,
            color: 'var(--color-chart-1)',
            y: centerY,
            height: centerHeight,
            column: 1,
        };

        // Create expense nodes (right column)
        let expenseY = 20;
        const expenseNodes = expense_categories
            .sort((a, b) => b.amount - a.amount)
            .map((item) => {
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
                height: linkHeight,
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
                height: linkHeight,
            });
            expenseLinkY += linkHeight;
        });

        return {
            nodes: Object.values(nodeMap),
            links: linkList,
            isEmpty: false,
        };
    }, [data, height]);

    if (isEmpty) {
        return (
            <div
                className={cn(
                    'flex items-center justify-center text-muted-foreground',
                    className,
                )}
                style={{ height }}
            >
                No cashflow data for this period
            </div>
        );
    }

    const width = 600; // SVG viewBox width

    return (
        <div className={cn('w-full overflow-x-auto', className)}>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                className="w-full"
                style={{ minWidth: 400, maxWidth: '100%' }}
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
                            M ${sourceX} ${link.sourceY - link.height / 2}
                            C ${(sourceX + targetX) / 2} ${link.sourceY - link.height / 2},
                              ${(sourceX + targetX) / 2} ${link.targetY - link.height / 2},
                              ${targetX} ${link.targetY - link.height / 2}
                            L ${targetX} ${link.targetY + link.height / 2}
                            C ${(sourceX + targetX) / 2} ${link.targetY + link.height / 2},
                              ${(sourceX + targetX) / 2} ${link.sourceY + link.height / 2},
                              ${sourceX} ${link.sourceY + link.height / 2}
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

                        return (
                            <g
                                key={node.id}
                                onMouseEnter={() => setHoveredNode(node.id)}
                                onMouseLeave={() => setHoveredNode(null)}
                                className="cursor-pointer"
                            >
                                <rect
                                    x={x}
                                    y={node.y}
                                    width={NODE_WIDTH}
                                    height={node.height}
                                    rx={4}
                                    fill={
                                        node.id === 'center'
                                            ? 'var(--color-chart-1)'
                                            : node.color
                                    }
                                    fillOpacity={isHovered ? 1 : 0.8}
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
                                    className="fill-foreground text-[9px] font-medium"
                                >
                                    {node.label}
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
                                    className="fill-muted-foreground text-[9px]"
                                >
                                    {formatAmount(node.value)}
                                </text>
                            </g>
                        );
                    })}
                </g>
            </svg>
        </div>
    );
}

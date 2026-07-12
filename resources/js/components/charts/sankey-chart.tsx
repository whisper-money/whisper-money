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

// Fields we attach to each node; recharts spreads these onto the payload it
// hands back to the custom node renderer, alongside its own layout data.
interface FlowNode {
    name: string;
    amount: number;
    color: string;
    kind: FlowKind;
    categoryId?: string;
}

interface FlowLink {
    source: number;
    target: number;
    value: number;
}

const NODE_WIDTH = 12;
const NODE_PADDING = 32;
const LABEL_GAP = 6;
const LABEL_HEIGHT = 30;
// A Sankey is inherently horizontal, so on narrow screens we let it scroll
// sideways (same pattern as the trend chart) rather than crushing the flows.
const MIN_CHART_WIDTH = 560;
// Guarantees enough vertical room per node that its two-line label never
// collides with the next one, even when a category's bar is tiny.
const ROW_HEIGHT = 54;
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
    const containerRef = useRef<HTMLDivElement>(null);
    const locale = useLocale();
    const { isPrivacyModeEnabled } = usePrivacyMode();
    const { cashflowIncomeColor, cashflowExpenseColor } = useChartColors();

    const maskIfPrivate = (value: number): string => {
        const formatted = formatCurrency(value, currency, locale, 0, 0);
        return isPrivacyModeEnabled ? formatted.replace(/\d/g, '*') : formatted;
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

    const { chartData, isEmpty, nodeRows } = useMemo(() => {
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
        groupedIncome.main.forEach((item) => {
            nodes.push({
                name: item.category.name,
                amount: item.amount,
                color: item.category.color || cashflowIncomeColor,
                kind: 'income',
                categoryId: item.category.id,
            });
        });
        if (groupedIncome.other) {
            nodes.push({
                name: __('Other'),
                amount: groupedIncome.other.total,
                color: MUTED_COLOR,
                kind: 'income',
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
        });

        const groupedExpense = groupSmallCategories(
            expense_categories,
            total_expense,
            groupingThreshold,
        );
        groupedExpense.main.forEach((item) => {
            nodes.push({
                name: item.category.name,
                amount: item.amount,
                color: item.category.color || cashflowExpenseColor,
                kind: 'expense',
                categoryId: item.category.id,
            });
        });
        if (groupedExpense.other) {
            nodes.push({
                name: __('Other'),
                amount: groupedExpense.other.total,
                color: MUTED_COLOR,
                kind: 'expense',
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

        const incomeRows = nodes.filter((n) => n.kind === 'income').length;
        const expenseRows = nodes.filter((n) => n.kind === 'expense').length;

        return {
            chartData: { nodes, links },
            isEmpty: links.length === 0,
            nodeRows: Math.max(incomeRows, expenseRows),
        };
    }, [data, groupingThreshold, cashflowIncomeColor, cashflowExpenseColor]);

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
        const isCenter = node.kind === 'center';
        const navigable = !!node.categoryId && !!period;

        let labelX: number;
        let foWidth: number;
        let labelY = y + nodeHeight / 2 - LABEL_HEIGHT / 2;
        let alignClass: string;

        if (node.kind === 'income') {
            labelX = 2;
            foWidth = Math.max(0, x - LABEL_GAP - 2);
            alignClass = 'items-end text-right';
        } else if (node.kind === 'expense') {
            labelX = x + width + LABEL_GAP;
            foWidth = Math.max(0, containerWidth - labelX - 2);
            alignClass = 'items-start text-left';
        } else {
            // The center bar spans the full height, so its label rides on top of
            // the bar with a subtle background instead of sitting beside it.
            foWidth = labelWidth;
            labelX = x + width / 2 - labelWidth / 2;
            labelY = y + nodeHeight / 2 - LABEL_HEIGHT / 2;
            alignClass = 'items-center text-center';
        }

        return (
            <Layer
                key={`node-${index}`}
                className={cn(navigable && 'cursor-pointer')}
                role={navigable ? 'link' : undefined}
                tabIndex={navigable ? 0 : undefined}
                aria-label={
                    navigable ? `View ${node.name} transactions` : undefined
                }
                onClick={
                    navigable ? () => goToCategory(node.categoryId!) : undefined
                }
                onKeyDown={
                    navigable
                        ? (event: KeyboardEvent) => {
                              if (event.key === 'Enter' || event.key === ' ') {
                                  event.preventDefault();
                                  goToCategory(node.categoryId!);
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
                    width={foWidth}
                    height={LABEL_HEIGHT}
                    className="overflow-visible"
                >
                    <div
                        className={cn(
                            'flex h-full flex-col justify-center gap-0.5 leading-tight',
                            alignClass,
                            isCenter &&
                                'rounded-md border border-border bg-background/90 px-1.5 py-0.5 shadow-sm',
                        )}
                    >
                        <span
                            title={node.name}
                            className="max-w-full truncate text-[11px] font-medium text-foreground"
                        >
                            {node.name}
                        </span>
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
            <div ref={containerRef} style={{ minWidth: MIN_CHART_WIDTH }}>
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

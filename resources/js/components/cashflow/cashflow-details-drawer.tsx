import {
    BLOCK_TONES,
    blockAmountsOf,
    blockLabel,
    BlockLink,
    BlockSwatch,
    type CashflowBlockHrefs,
    isExpenseBlock,
    StackedBar,
    type StackedBarSegment,
} from '@/components/cashflow/cashflow-blocks';
import {
    shareOf,
    signTone,
    WholeAmount,
} from '@/components/cashflow/summary-card-parts';
import { Button } from '@/components/ui/button';
import {
    Drawer,
    DrawerClose,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
} from '@/components/ui/drawer';
import {
    CashflowBudgetMargin,
    CashflowPeriodType,
    CashflowSummary,
} from '@/hooks/use-cashflow-data';
import {
    allocatedOf,
    availableOf,
    freeOf,
    hasPeriodData,
} from '@/lib/cashflow-available';
import { CASHFLOW_BLOCKS } from '@/lib/cashflow-block';
import { cn } from '@/lib/utils';
import { type CashflowBlock } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Minus, TrendingDown, TrendingUp, X } from 'lucide-react';
import { type ReactNode } from 'react';

interface CashflowDetailsDrawerProps {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    current: CashflowSummary;
    previous: CashflowSummary;
    currency: string;
    periodType: CashflowPeriodType;
    periodLabel: string;
    hrefs?: CashflowBlockHrefs;
}

function StatTile({
    label,
    caption,
    children,
}: {
    label: string;
    caption?: ReactNode;
    children: ReactNode;
}) {
    return (
        <div className="rounded-lg bg-muted/50 p-4">
            <p className="text-xs text-muted-foreground">{label}</p>
            <div className="mt-1 text-lg font-semibold tabular-nums">
                {children}
            </div>
            {caption && (
                <p className="text-xs text-muted-foreground">{caption}</p>
            )}
        </div>
    );
}

/**
 * The seven parts the income went to. Reserved and free together are what was
 * available, so reserved never shows more than that: budgets cannot hold back
 * money the period did not have. Whatever is negative draws nothing.
 */
function incomeSegments(summary: CashflowSummary): StackedBarSegment[] {
    const amounts = blockAmountsOf(summary);
    const available = Math.max(0, availableOf(summary));
    const reserved = Math.min(Math.max(0, summary.reserved ?? 0), available);

    return [
        ...CASHFLOW_BLOCKS.map((block) => ({
            key: block,
            label: blockLabel(block),
            amount: amounts[block],
            tone: BLOCK_TONES[block],
        })),
        {
            key: 'reserved',
            label: __('Reserved'),
            amount: reserved,
            tone: BLOCK_TONES.reserved,
        },
        {
            key: 'free',
            label: __('Free (cashflow)'),
            amount: available - reserved,
            tone: BLOCK_TONES.free,
        },
    ];
}

function IncomeBar({
    summary,
    currency,
}: {
    summary: CashflowSummary;
    currency: string;
}) {
    // With nothing coming in there is no income to split, so the bar stays
    // empty rather than filling up with what went out.
    const hasIncome = summary.income > 0;
    const segments = incomeSegments(summary);
    const available = availableOf(summary);

    return (
        <section className="flex flex-col gap-3">
            <StackedBar
                className="h-3"
                segments={hasIncome ? segments : []}
                total={summary.income}
                ariaLabel={segments
                    .map(
                        (segment) =>
                            `${segment.label} ${shareOf(Math.max(0, segment.amount), summary.income).toFixed(0)}%`,
                    )
                    .join(', ')}
            />
            <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
                {segments.map((segment) => (
                    <li key={segment.key} className="flex items-center gap-1.5">
                        <BlockSwatch tone={segment.tone} />
                        {segment.label}
                    </li>
                ))}
            </ul>
            {!hasIncome && (
                <p className="text-xs text-muted-foreground">
                    {__('No income in this period')}
                </p>
            )}
            {hasIncome && available < 0 && (
                <p className="text-xs text-red-600 dark:text-red-400">
                    {__('You allocated more than you earned:')}{' '}
                    <WholeAmount
                        amountInCents={-available}
                        currencyCode={currency}
                        className="text-xs"
                    />
                </p>
            )}
        </section>
    );
}

function PreviousChange({
    block,
    diff,
    currency,
}: {
    block: CashflowBlock;
    diff: number;
    currency: string;
}) {
    if (diff === 0) {
        return (
            <span className="inline-flex items-center justify-end gap-1 text-muted-foreground">
                <Minus className="size-3" />
                <WholeAmount
                    amountInCents={0}
                    currencyCode={currency}
                    className="text-sm"
                />
            </span>
        );
    }

    const isIncrease = diff > 0;
    // Spending less is the good news; contributing less is not.
    const isFavorable = isExpenseBlock(block) ? diff <= 0 : diff >= 0;
    const Icon = isIncrease ? TrendingUp : TrendingDown;

    return (
        <span className="inline-flex items-center justify-end gap-1">
            <Icon
                className={cn(
                    'size-3',
                    isFavorable
                        ? 'text-green-600 dark:text-green-400'
                        : 'text-red-600 dark:text-red-400',
                )}
            />
            <span className="whitespace-nowrap">
                {isIncrease ? '+' : ''}
                <WholeAmount
                    amountInCents={diff}
                    currencyCode={currency}
                    className="text-sm"
                />
            </span>
        </span>
    );
}

function BlocksTable({
    current,
    previous,
    currency,
    hrefs,
    onNavigate,
}: {
    current: CashflowSummary;
    previous: CashflowSummary;
    currency: string;
    hrefs: CashflowBlockHrefs;
    onNavigate: () => void;
}) {
    const amounts = blockAmountsOf(current);
    const previousAmounts = blockAmountsOf(previous);
    const hasPreviousData = hasPeriodData(previous);

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead className="text-xs text-muted-foreground">
                    <tr className="border-b">
                        <th scope="col" className="py-2 text-left font-normal">
                            {__('Block')}
                        </th>
                        <th scope="col" className="py-2 text-right font-normal">
                            {__('% of income')}
                        </th>
                        <th scope="col" className="py-2 text-right font-normal">
                            {__('Amount (cashflow)')}
                        </th>
                        <th scope="col" className="py-2 text-right font-normal">
                            {__('vs previous')}
                        </th>
                        <th scope="col" className="w-6">
                            <span className="sr-only">
                                {__('Transactions')}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {CASHFLOW_BLOCKS.map((block) => (
                        <tr key={block} className="border-b last:border-0">
                            <td className="py-2">
                                <span className="flex items-center gap-2">
                                    <BlockSwatch tone={BLOCK_TONES[block]} />
                                    {blockLabel(block)}
                                </span>
                            </td>
                            <td className="py-2 text-right tabular-nums">
                                {shareOf(
                                    amounts[block],
                                    current.income,
                                ).toFixed(1)}
                                %
                            </td>
                            <td className="py-2 text-right">
                                <WholeAmount
                                    amountInCents={amounts[block]}
                                    currencyCode={currency}
                                    weight="medium"
                                />
                            </td>
                            <td className="py-2 text-right">
                                {hasPreviousData ? (
                                    <PreviousChange
                                        block={block}
                                        diff={
                                            amounts[block] -
                                            previousAmounts[block]
                                        }
                                        currency={currency}
                                    />
                                ) : (
                                    <span className="text-muted-foreground">
                                        —
                                    </span>
                                )}
                            </td>
                            <td className="py-2 pl-2 text-right">
                                <BlockLink
                                    href={hrefs[block]}
                                    block={block}
                                    onNavigate={onNavigate}
                                />
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

function BudgetsPanel({
    title,
    emptyText,
    budgets,
    currency,
}: {
    title: string;
    emptyText: string;
    budgets: CashflowBudgetMargin[];
    currency: string;
}) {
    const reserved = budgets.reduce(
        (sum, budget) => sum + Math.max(0, budget.margin),
        0,
    );

    return (
        <section className="rounded-lg border p-4">
            <div className="flex items-baseline justify-between gap-2">
                <h3 className="text-sm font-medium">{title}</h3>
                <p className="text-xs text-muted-foreground">
                    <WholeAmount
                        amountInCents={reserved}
                        currencyCode={currency}
                        className="text-xs"
                    />{' '}
                    {__('reserved')}
                </p>
            </div>
            {budgets.length === 0 ? (
                <p className="mt-3 text-xs text-muted-foreground">
                    {emptyText}
                </p>
            ) : (
                <ul className="mt-3 flex flex-col gap-2">
                    {budgets.map((budget) => (
                        <li
                            key={budget.budget_period_id}
                            className="flex items-center justify-between gap-3 text-sm"
                        >
                            <div className="min-w-0">
                                <p className="truncate">{budget.name}</p>
                                <p className="text-xs text-muted-foreground">
                                    <WholeAmount
                                        amountInCents={budget.spent}
                                        currencyCode={currency}
                                        className="text-xs"
                                    />{' '}
                                    {__('of')}{' '}
                                    <WholeAmount
                                        amountInCents={budget.allocated}
                                        currencyCode={currency}
                                        className="text-xs"
                                    />
                                </p>
                            </div>
                            <WholeAmount
                                amountInCents={budget.margin}
                                currencyCode={currency}
                                weight="medium"
                                className={signTone(budget.margin)}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

export function CashflowDetailsDrawer({
    open,
    onOpenChange,
    current,
    previous,
    currency,
    periodType,
    periodLabel,
    hrefs = {},
}: CashflowDetailsDrawerProps) {
    const available = availableOf(current);
    const free = freeOf(current);
    const budgets = current.budgets ?? [];

    return (
        <Drawer open={open} onOpenChange={onOpenChange}>
            <DrawerContent
                className="h-[90vh] data-[vaul-drawer-direction=bottom]:max-h-[90vh]"
                data-testid="cashflow-details-drawer"
            >
                <div className="mx-auto flex w-full max-w-5xl flex-col gap-6 overflow-y-auto p-6">
                    <DrawerHeader className="relative gap-0 px-0">
                        <DrawerClose asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                className="absolute -top-4 right-0"
                                aria-label={__('Close')}
                            >
                                <X className="size-4" />
                            </Button>
                        </DrawerClose>
                        <div className="my-4 flex flex-col items-center gap-2 text-center">
                            <DrawerTitle className="text-xl">
                                {__('Where your money goes · :period', {
                                    period: periodLabel,
                                })}
                            </DrawerTitle>
                            <DrawerDescription className="text-sm text-pretty">
                                {__(
                                    'What you allocated from your income, by block, and what is left free.',
                                )}
                            </DrawerDescription>
                        </div>
                    </DrawerHeader>

                    <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
                        <StatTile label={__('Income')}>
                            <WholeAmount
                                amountInCents={current.income}
                                currencyCode={currency}
                                className="text-lg"
                            />
                        </StatTile>
                        <StatTile label={__('Allocated')}>
                            <WholeAmount
                                amountInCents={allocatedOf(current)}
                                currencyCode={currency}
                                className="text-lg"
                            />
                        </StatTile>
                        <StatTile label={__('Available')}>
                            <WholeAmount
                                amountInCents={available}
                                currencyCode={currency}
                                className={cn('text-lg', signTone(available))}
                            />
                        </StatTile>
                        <StatTile
                            label={__('Free (cashflow)')}
                            caption={
                                <>
                                    <WholeAmount
                                        amountInCents={current.reserved ?? 0}
                                        currencyCode={currency}
                                        className="text-xs"
                                    />{' '}
                                    {__('reserved')}
                                </>
                            }
                        >
                            <WholeAmount
                                amountInCents={free}
                                currencyCode={currency}
                                className={cn('text-lg', signTone(free))}
                            />
                        </StatTile>
                    </div>

                    <IncomeBar summary={current} currency={currency} />

                    <BlocksTable
                        current={current}
                        previous={previous}
                        currency={currency}
                        hrefs={hrefs}
                        // Closed before the visit, so coming back finds the
                        // page as it was, not a drawer left open over it.
                        onNavigate={() => onOpenChange(false)}
                    />

                    <div className="grid gap-3 md:grid-cols-2">
                        <BudgetsPanel
                            title={__('Fixed budgets')}
                            emptyText={__(
                                'No fixed budget in progress in this view.',
                            )}
                            budgets={budgets.filter(
                                (budget) => budget.expense_type === 'fixed',
                            )}
                            currency={currency}
                        />
                        <BudgetsPanel
                            title={__('Variable budgets')}
                            emptyText={__(
                                'No variable budget in progress in this view.',
                            )}
                            budgets={budgets.filter(
                                (budget) => budget.expense_type === 'variable',
                            )}
                            currency={currency}
                        />
                    </div>

                    <p className="text-center text-xs text-muted-foreground">
                        {periodType === 'year'
                            ? __(
                                  'Weekly and monthly budgets are reserved in the Month view',
                              )
                            : __(
                                  'Yearly budgets are reserved in the Year view',
                              )}
                    </p>
                </div>
            </DrawerContent>
        </Drawer>
    );
}

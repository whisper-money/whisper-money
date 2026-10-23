import {
    BLOCK_TONES,
    blockLabel,
    BlockLink,
    BlockSwatch,
    type CashflowBlockHrefs,
    EXPENSE_BLOCKS,
    expenseBlockHint,
    StackedBar,
} from '@/components/cashflow/cashflow-blocks';
import {
    shareOf,
    SummaryCardSkeleton,
    SummaryHeadline,
    WholeAmount,
} from '@/components/cashflow/summary-card-parts';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { expenseSplitOf } from '@/lib/cashflow-available';
import { __ } from '@/utils/i18n';

interface ExpensesCardProps {
    current: CashflowSummary;
    loading?: boolean;
    currency?: string;
    hrefs?: CashflowBlockHrefs;
    onViewDetails: () => void;
}

export function ExpensesCard({
    current,
    loading,
    currency = 'USD',
    hrefs = {},
    onViewDetails,
}: ExpensesCardProps) {
    if (loading) {
        return <SummaryCardSkeleton title={__('Expenses (cashflow)')} />;
    }

    const split = expenseSplitOf(current);
    const segments = EXPENSE_BLOCKS.map((block) => ({
        key: block,
        label: blockLabel(block),
        amount: split[block],
        tone: BLOCK_TONES[block],
    }));

    return (
        <Card>
            <CardHeader className="pb-2">
                <CardTitle className="text-sm font-medium">
                    {__('Expenses (cashflow)')}
                </CardTitle>
                <CardDescription>
                    {__('Share of income spent, by budget type')}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex-1">
                <SummaryHeadline
                    amount={current.expense}
                    currency={currency}
                    rate={shareOf(current.expense, current.income)}
                    amountClassName="text-foreground"
                />
                <StackedBar
                    className="mt-3"
                    segments={segments}
                    ariaLabel={__(
                        'Expense split: :fixed% fixed, :variable% variable, :general% general',
                        {
                            fixed: shareOf(
                                split.fixed,
                                current.expense,
                            ).toFixed(0),
                            variable: shareOf(
                                split.variable,
                                current.expense,
                            ).toFixed(0),
                            general: shareOf(
                                split.general,
                                current.expense,
                            ).toFixed(0),
                        },
                    )}
                />
                <ul className="mt-3 flex flex-col gap-2 border-t pt-3">
                    {EXPENSE_BLOCKS.map((block) => (
                        <li key={block} className="flex items-center gap-2">
                            <BlockSwatch tone={BLOCK_TONES[block]} />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm leading-tight">
                                    {blockLabel(block)}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {expenseBlockHint(block)}
                                </p>
                            </div>
                            <WholeAmount
                                amountInCents={split[block]}
                                currencyCode={currency}
                                weight="medium"
                            />
                            <BlockLink href={hrefs[block]} block={block} />
                        </li>
                    ))}
                </ul>
            </CardContent>
            <CardFooter>
                <Button
                    variant="outline"
                    size="sm"
                    className="w-full"
                    onClick={onViewDetails}
                >
                    {__('View details')}
                </Button>
            </CardFooter>
        </Card>
    );
}

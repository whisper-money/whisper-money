import { CashflowSummary } from '@/hooks/use-cashflow-data';
import { expenseSplitOf } from '@/lib/cashflow-available';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';

/** The expense blocks first, then what was contributed. */
export type ExpenseBlock = 'fixed' | 'variable' | 'general';
export type CashflowBlock = ExpenseBlock | 'saved' | 'invested';

/** A "view transactions" link per block, for the rows that have one. */
export type CashflowBlockHrefs = Partial<Record<CashflowBlock, string>>;

export const EXPENSE_BLOCKS: ExpenseBlock[] = ['fixed', 'variable', 'general'];
export const CASHFLOW_BLOCKS: CashflowBlock[] = [
    ...EXPENSE_BLOCKS,
    'saved',
    'invested',
];

// Each block reads apart from its neighbours by lightness, not only by hue, so
// the bars still split for anyone who cannot tell rose from emerald.
export const BLOCK_TONES: Record<CashflowBlock | 'reserved' | 'free', string> =
    {
        fixed: 'bg-rose-900 dark:bg-rose-700',
        variable: 'bg-rose-500 dark:bg-rose-400',
        general: 'bg-neutral-400 dark:bg-neutral-500',
        saved: 'bg-emerald-700 dark:bg-emerald-600',
        invested: 'bg-blue-700 dark:bg-blue-500',
        reserved: 'bg-neutral-300 dark:bg-neutral-600',
        free: 'bg-emerald-300 dark:bg-emerald-300',
    };

export function blockLabel(block: CashflowBlock): string {
    switch (block) {
        case 'fixed':
            return __('Fixed');
        case 'variable':
            return __('Variable');
        case 'general':
            return __('General');
        case 'saved':
            return __('Saved (cashflow)');
        case 'invested':
            return __('Invested');
    }
}

export function expenseBlockHint(block: ExpenseBlock): string {
    switch (block) {
        case 'fixed':
            return __('Fixed budgets');
        case 'variable':
            return __('Variable budgets');
        case 'general':
            return __('No budget');
    }
}

/** Every block's amount in the period. */
export function blockAmountsOf(
    summary: CashflowSummary,
): Record<CashflowBlock, number> {
    return {
        ...expenseSplitOf(summary),
        saved: summary.savings,
        invested: summary.investments,
    };
}

export function BlockSwatch({ tone }: { tone: string }) {
    return (
        <span
            aria-hidden
            className={cn('size-2.5 shrink-0 rounded-sm', tone)}
        />
    );
}

export interface StackedBarSegment {
    key: string;
    label: string;
    amount: number;
    tone: string;
}

interface StackedBarProps {
    segments: StackedBarSegment[];
    /**
     * What a full bar stands for. Defaults to the segments' sum; when they add
     * up to more, the bar scales to them so it never overflows.
     */
    total?: number;
    ariaLabel: string;
    className?: string;
}

/**
 * Segments side by side, each as wide as its share of the total. A negative
 * segment (a month of refunds, money pulled out of savings) draws nothing:
 * a bar cannot show less than empty.
 */
export function StackedBar({
    segments,
    total,
    ariaLabel,
    className,
}: StackedBarProps) {
    const visible = segments.map((segment) => ({
        ...segment,
        amount: Math.max(0, segment.amount),
    }));
    const sum = visible.reduce((acc, segment) => acc + segment.amount, 0);
    const whole = Math.max(total ?? 0, sum);

    return (
        <div
            role="img"
            aria-label={ariaLabel}
            className={cn(
                'flex h-1.5 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            {whole > 0 &&
                visible.map(
                    (segment) =>
                        segment.amount > 0 && (
                            <div
                                key={segment.key}
                                data-segment={segment.key}
                                className={segment.tone}
                                style={{
                                    width: `${(segment.amount / whole) * 100}%`,
                                }}
                            />
                        ),
                )}
        </div>
    );
}

/** The arrow to a block's transactions, when the block has a link. */
export function BlockLink({
    href,
    block,
}: {
    href?: string;
    block: CashflowBlock;
}) {
    if (!href) {
        return null;
    }

    return (
        <Link
            href={href}
            aria-label={__('View :block transactions', {
                block: blockLabel(block),
            })}
            className="text-muted-foreground transition-colors hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <ArrowRight className="size-4" />
        </Link>
    );
}

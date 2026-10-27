import { BankLogo } from '@/components/bank-logo';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { SplitOriginPopover } from '@/components/transactions/split-origin-popover';
import { StatementPaymentNote } from '@/components/transactions/statement-payment-note';
import { TransactionDescription } from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { COMPACT_LIST_MUTED_TEXT } from '@/lib/compact-list-style';
import { resolveTransactionLabels } from '@/lib/transaction-labels';
import { isSplitPart } from '@/lib/transaction-splits';
import { cn } from '@/lib/utils';
import { type CreditCardPayment } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Check, HelpCircle } from 'lucide-react';
import { type ComponentProps, type MouseEvent } from 'react';

interface TransactionListRowProps extends Omit<
    ComponentProps<'div'>,
    'onClick' | 'children'
> {
    transaction: ServerTransaction;
    categories: Category[];
    labels: Label[];
    selected: boolean;
    /** Rows are being picked: a tap anywhere on the row toggles it. */
    selectionActive: boolean;
    onToggleSelect: (transaction: ServerTransaction) => void;
    /** A tap on the row outside selection mode, i.e. edit. */
    onOpen: (transaction: ServerTransaction) => void;
    onUnsplit: (transaction: ServerTransaction) => void;
    /** AI is currently categorizing this transaction in the background. */
    isCategorizing?: boolean;
    /** A label every row already carries, so showing it would say nothing. */
    hiddenLabelId?: string;
    /** The statement this credit card repayment pays, if it is one. */
    statementPayment?: CreditCardPayment;
}

/**
 * One transaction as a line of the compact list, for widths too narrow for
 * the table. Read-only apart from its circle: the category and everything else
 * change in the edit dialog a tap opens. Purely presentational: selection and
 * editing belong to whoever renders it.
 */
export function TransactionListRow({
    transaction,
    categories,
    labels,
    selected,
    selectionActive,
    onToggleSelect,
    onOpen,
    onUnsplit,
    isCategorizing,
    hiddenLabelId,
    statementPayment,
    className,
    ...props
}: TransactionListRowProps) {
    const category =
        categories.find((c) => c.id === transaction.category_id) ?? null;
    const transactionLabels = resolveTransactionLabels(
        transaction,
        labels,
        hiddenLabelId,
    );

    function handleRowClick() {
        if (selectionActive) {
            onToggleSelect(transaction);
        } else {
            onOpen(transaction);
        }
    }

    return (
        <div
            {...props}
            onClick={handleRowClick}
            data-selected={selected || undefined}
            className={cn(
                'flex cursor-pointer items-start gap-3 border-b border-neutral-100 px-4 py-3 leading-[1.4] transition-colors select-none dark:border-neutral-800',
                selected
                    ? 'bg-primary/5 dark:bg-primary/10'
                    : 'hover:bg-muted/50',
                className,
            )}
            data-testid="transaction-list-row"
        >
            <SelectionCircle
                category={category}
                selected={selected}
                isCategorizing={isCategorizing}
                label={__('Select :description', {
                    description: transaction.description,
                })}
                onClick={(event) => {
                    event.stopPropagation();
                    onToggleSelect(transaction);
                }}
            />

            <div
                // While selecting, a tap on the split mark reaches the row and
                // toggles it, rather than opening its popover.
                inert={selectionActive}
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-0.5',
                    selectionActive && 'pointer-events-none',
                )}
            >
                <div className="flex items-baseline gap-2">
                    {isSplitPart(transaction) && (
                        // Its popover renders in a portal, whose clicks still
                        // bubble through React to the row.
                        <span
                            className="self-center"
                            onClick={(event) => event.stopPropagation()}
                        >
                            <SplitOriginPopover
                                transaction={transaction}
                                categories={categories}
                                onUnsplit={onUnsplit}
                            />
                        </span>
                    )}
                    {/* The row's keyboard entry point: the click bubbles to
                        the row, which opens it. */}
                    <button
                        type="button"
                        className="min-w-0 flex-1 truncate rounded-sm text-left text-[15px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                    >
                        <TransactionDescription
                            text={transaction.description}
                        />
                    </button>
                    <AmountDisplay
                        amountInCents={transaction.amount}
                        currencyCode={transaction.currency_code}
                        variant="positive-highlight"
                        highlightPositive={transaction.amount > 0}
                        showSign
                        weight="medium"
                        monospace
                        // Its income pill pads it; the figure itself lines up
                        // with the row's edge like every other amount.
                        className="-mr-1 shrink-0 text-[15px]"
                    />
                </div>

                <div
                    className={cn(
                        'flex min-w-0 items-center gap-1.5 overflow-hidden text-[13px] whitespace-nowrap',
                        COMPACT_LIST_MUTED_TEXT,
                    )}
                >
                    <CategoryName
                        category={category}
                        isCategorizing={isCategorizing}
                    />
                    {transaction.account && (
                        <>
                            <span aria-hidden>·</span>
                            <BankLogo
                                src={transaction.bank?.logo}
                                name={transaction.bank?.name}
                                fallback="letter"
                                className="size-3.5 shrink-0 rounded text-[8px]"
                            />
                            <span className="min-w-8 truncate">
                                {transaction.account.name}
                            </span>
                        </>
                    )}
                </div>

                {transactionLabels.length > 0 && (
                    // Their own line, wrapping rather than truncating: a label
                    // cut to "Rec…" says nothing.
                    <div className="flex flex-wrap gap-1 pt-0.5">
                        {transactionLabels.map((label) => (
                            <span
                                key={label.id}
                                className="rounded-full bg-neutral-100 px-1.5 py-px text-[11px] font-medium text-neutral-700 dark:bg-neutral-800 dark:text-neutral-300"
                            >
                                {label.name}
                            </span>
                        ))}
                    </div>
                )}

                {transaction.notes && (
                    <span
                        className={cn(
                            'truncate text-[13px]',
                            COMPACT_LIST_MUTED_TEXT,
                        )}
                    >
                        {transaction.notes}
                    </span>
                )}

                {statementPayment && (
                    <StatementPaymentNote payment={statementPayment} />
                )}
            </div>
        </div>
    );
}

function CategoryName({
    category,
    isCategorizing,
}: {
    category: Category | null;
    isCategorizing?: boolean;
}) {
    if (isCategorizing) {
        return (
            <span className="shrink-0 animate-pulse">
                {__('Categorizing…')}
            </span>
        );
    }

    if (!category) {
        return (
            <span className="shrink-0 font-medium text-amber-700 dark:text-amber-400">
                {__('Uncategorized')}
            </span>
        );
    }

    return <span className="shrink-0">{category.name}</span>;
}

/**
 * The category's circle, which doubles as the row's checkbox: a 40px circle
 * in a 44px button, so a fingertip finds it.
 */
function SelectionCircle({
    category,
    selected,
    isCategorizing,
    label,
    onClick,
}: {
    category: Category | null;
    selected: boolean;
    isCategorizing?: boolean;
    label: string;
    onClick: (event: MouseEvent<HTMLButtonElement>) => void;
}) {
    const circle = 'size-10 shrink-0 rounded-full';

    return (
        <button
            type="button"
            aria-pressed={selected}
            aria-label={label}
            onClick={onClick}
            className="-m-0.5 flex size-11 shrink-0 items-center justify-center self-start rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
            data-testid="transaction-row-circle"
        >
            {selected ? (
                <span
                    className={cn(
                        circle,
                        'flex items-center justify-center bg-primary text-primary-foreground',
                    )}
                >
                    <Check className="size-[18px]" strokeWidth={3} />
                </span>
            ) : isCategorizing ? (
                <span className={cn(circle, 'block animate-pulse bg-muted')} />
            ) : category ? (
                <CategoryIcon
                    category={category}
                    className={circle}
                    iconClassName="size-[18px] sm:size-[18px]"
                />
            ) : (
                <span
                    className={cn(
                        circle,
                        'flex items-center justify-center border-[1.5px] border-dashed border-amber-600 bg-background text-amber-700 dark:border-amber-500 dark:text-amber-400',
                    )}
                >
                    <HelpCircle className="size-[18px]" />
                </span>
            )}
        </button>
    );
}

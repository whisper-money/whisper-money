import { BankLogo } from '@/components/bank-logo';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { LabelBadges } from '@/components/shared/label-combobox';
import { CategoryCell } from '@/components/transactions/category-cell';
import { SplitOriginPopover } from '@/components/transactions/split-origin-popover';
import { StatementPaymentNote } from '@/components/transactions/statement-payment-note';
import { TransactionDescription } from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { isSplitPart } from '@/lib/transaction-splits';
import { cn } from '@/lib/utils';
import {
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Check, ChevronDown, HelpCircle } from 'lucide-react';
import { type ComponentProps, type MouseEvent } from 'react';

interface TransactionCardProps extends Omit<
    ComponentProps<'div'>,
    'onClick' | 'children'
> {
    transaction: ServerTransaction;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    selected: boolean;
    /** Rows are being picked: a tap anywhere on the card toggles it. */
    selectionActive: boolean;
    onToggleSelect: (transaction: ServerTransaction) => void;
    /** A tap on the card body outside selection mode, i.e. edit. */
    onOpen: (transaction: ServerTransaction) => void;
    onUpdate: (transaction: ServerTransaction) => void;
    onCategorized?: (
        transaction: ServerTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    onUnsplit: (transaction: ServerTransaction) => void;
    /** AI is currently categorizing this transaction in the background. */
    isCategorizing?: boolean;
    /** A label every card already carries, so showing it would say nothing. */
    hiddenLabelId?: string;
    /** The statement this credit card repayment pays, if it is one. */
    statementPayment?: CreditCardPayment;
}

/**
 * One transaction as a card, for widths too narrow for the table. Purely
 * presentational: selection and editing belong to whoever renders it.
 */
export function TransactionCard({
    transaction,
    categories,
    accounts,
    banks,
    labels,
    selected,
    selectionActive,
    onToggleSelect,
    onOpen,
    onUpdate,
    onCategorized,
    onUnsplit,
    isCategorizing,
    hiddenLabelId,
    statementPayment,
    className,
    ...props
}: TransactionCardProps) {
    const category =
        categories.find((c) => c.id === transaction.category_id) ?? null;

    const transactionLabels = (transaction.label_ids || [])
        .filter((id) => id !== hiddenLabelId)
        .map((id) => labels.find((l) => l.id === id))
        .filter(Boolean) as Label[];

    function handleCardClick() {
        if (selectionActive) {
            onToggleSelect(transaction);
        } else {
            onOpen(transaction);
        }
    }

    // Pickers render in portals, whose clicks still bubble through React to
    // the card and would open the edit dialog behind them.
    const keepClickInside = (event: MouseEvent) => event.stopPropagation();

    return (
        <div
            {...props}
            onClick={handleCardClick}
            data-selected={selected || undefined}
            className={cn(
                'flex cursor-pointer gap-3 rounded-xl border bg-card p-3 transition-colors select-none',
                selected
                    ? 'border-primary/50 bg-primary/5 dark:border-primary/60 dark:bg-primary/10'
                    : 'hover:bg-muted/50',
                className,
            )}
            data-testid="transaction-card"
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
                // While selecting, taps and keys on the chips reach the card
                // and toggle it, rather than opening a picker.
                inert={selectionActive}
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-1',
                    selectionActive && 'pointer-events-none',
                )}
            >
                <div className="flex items-baseline justify-between gap-2">
                    <div className="flex min-w-0 items-center gap-1">
                        {isSplitPart(transaction) && (
                            <span onClick={keepClickInside}>
                                <SplitOriginPopover
                                    transaction={transaction}
                                    categories={categories}
                                    onUnsplit={onUnsplit}
                                />
                            </span>
                        )}
                        {/* The card's keyboard entry point: the click bubbles to
                            the card, which opens it. */}
                        <button
                            type="button"
                            className="min-w-0 truncate rounded-sm text-left font-semibold outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            <TransactionDescription
                                text={transaction.description}
                            />
                        </button>
                    </div>
                    <AmountDisplay
                        amountInCents={transaction.amount}
                        currencyCode={transaction.currency_code}
                        variant="positive-highlight"
                        highlightPositive={transaction.amount >= 0}
                        monospace
                        className="shrink-0"
                    />
                </div>

                <div className="flex min-w-0 items-center gap-1.5 text-sm text-muted-foreground">
                    {transaction.account && (
                        <>
                            <BankLogo
                                src={transaction.bank?.logo}
                                name={transaction.bank?.name}
                                className="size-4 shrink-0"
                            />
                            <span className="min-w-0 truncate">
                                {transaction.account.name}
                            </span>
                        </>
                    )}
                    {transaction.notes && (
                        <>
                            {transaction.account && <span aria-hidden>·</span>}
                            <span className="min-w-0 truncate text-muted-foreground/80">
                                {transaction.notes}
                            </span>
                        </>
                    )}
                </div>

                {statementPayment && (
                    <StatementPaymentNote payment={statementPayment} />
                )}

                <div className="flex flex-wrap items-center gap-1.5 pt-1">
                    <div onClick={keepClickInside} className="max-w-full">
                        <CategoryCell
                            transaction={transaction}
                            categories={categories}
                            accounts={accounts}
                            banks={banks}
                            onUpdate={onUpdate}
                            onCategorized={onCategorized}
                            isCategorizing={isCategorizing}
                            className="h-8"
                            trigger={<CategoryChip category={category} />}
                        />
                    </div>
                    <LabelBadges labels={transactionLabels} max={3} />
                </div>
            </div>
        </div>
    );
}

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
    const circle = 'size-11 shrink-0 rounded-full';

    return (
        <button
            type="button"
            aria-pressed={selected}
            aria-label={label}
            onClick={onClick}
            className="shrink-0 self-start rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring"
            data-testid="transaction-card-circle"
        >
            {selected ? (
                <span
                    className={cn(
                        circle,
                        'flex items-center justify-center bg-primary text-primary-foreground',
                    )}
                >
                    <Check className="size-5" strokeWidth={3} />
                </span>
            ) : isCategorizing ? (
                <span className={cn(circle, 'block animate-pulse bg-muted')} />
            ) : category ? (
                <CategoryIcon
                    category={category}
                    className={circle}
                    iconClassName="size-5 sm:size-5"
                />
            ) : (
                <span
                    className={cn(
                        circle,
                        'flex items-center justify-center border-2 border-dashed border-amber-400 bg-amber-50 text-muted-foreground dark:border-amber-500/70 dark:bg-amber-500/10',
                    )}
                >
                    <HelpCircle className="size-5" />
                </span>
            )}
        </button>
    );
}

/**
 * The trigger of the category picker on a card. The picker hands it the props
 * and ref a Radix trigger needs, so they are spread onto the button.
 */
function CategoryChip({
    category,
    className,
    ...props
}: ComponentProps<'button'> & { category: Category | null }) {
    const colorClasses = category
        ? getCategoryColorClasses(category.color)
        : null;

    return (
        <button
            type="button"
            {...props}
            className={cn(
                'inline-flex h-8 max-w-full min-w-0 items-center gap-1 rounded-full px-3 text-xs font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring',
                colorClasses
                    ? [colorClasses.bg, colorClasses.text]
                    : 'border border-dashed border-amber-400 text-amber-700 dark:border-amber-500/70 dark:text-amber-300',
                className,
            )}
            data-testid="transaction-card-category"
        >
            <span className="truncate">
                {category ? category.name : __('Choose category')}
            </span>
            <ChevronDown className="size-3.5 shrink-0 opacity-70" />
        </button>
    );
}

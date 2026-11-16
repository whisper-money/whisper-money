import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { CategoryIcon } from '@/components/shared/category-combobox';
import {
    buildSplitBreakdown,
    type SplitBreakdownLine,
} from '@/components/transactions/split-breakdown';
import { StatementPaymentNote } from '@/components/transactions/statement-payment-note';
import {
    ENCRYPTED_PLACEHOLDER,
    TransactionBadge,
    TransactionDescription,
} from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { COMPACT_LIST_MUTED_TEXT, LIST_ROW } from '@/lib/compact-list-style';
import { resolveTransactionLabels } from '@/lib/transaction-labels';
import { cn } from '@/lib/utils';
import { type CreditCardPayment } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Check, HelpCircle, Split } from 'lucide-react';
import { type ComponentProps, type MouseEvent } from 'react';

interface TransactionListRowProps extends Omit<
    ComponentProps<'div'>,
    'onClick' | 'children'
> {
    transaction: DecryptedTransaction;
    categories: Category[];
    labels: Label[];
    selected: boolean;
    /** Rows are being picked: a tap anywhere on the row toggles it. */
    selectionActive: boolean;
    /** Without it the category circle is only a picture: nothing to pick. */
    onToggleSelect?: (transaction: DecryptedTransaction) => void;
    /**
     * A tap on the row outside selection mode, i.e. edit. Without it the row
     * is read-only: no pointer, no hover, nothing to focus.
     */
    onOpen?: (transaction: DecryptedTransaction) => void;
    /** It arrived since the last visit. */
    isNew?: boolean;
    /** A short tag after the description, e.g. a widget's "Purchase". */
    badge?: string;
    /** AI is currently categorizing this transaction in the background. */
    isCategorizing?: boolean;
    /** Lists a split's lines in these categories, the rest as one. */
    categoryFilterIds?: ReadonlySet<string>;
    /** A label every row already carries, so showing it would say nothing. */
    hiddenLabelId?: string;
    /** The statement this credit card repayment pays, if it is one. */
    statementPayment?: CreditCardPayment;
}

/**
 * One transaction as a line of the compact list, for widths too narrow for
 * the table. Read-only apart from its circle: the category and everything else
 * change in the edit dialog a tap opens, a split's lines included. Purely
 * presentational: selection and editing belong to whoever renders it.
 */
export function TransactionListRow({
    transaction,
    categories,
    labels,
    selected,
    selectionActive,
    onToggleSelect,
    onOpen,
    isNew = false,
    isCategorizing,
    categoryFilterIds,
    hiddenLabelId,
    statementPayment,
    badge,
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
    const isEncrypted = !!transaction.description_iv;
    const splitLines = transaction.splits ?? [];

    const isInteractive = selectionActive
        ? onToggleSelect !== undefined
        : onOpen !== undefined;

    function handleRowClick() {
        if (selectionActive) {
            onToggleSelect?.(transaction);
        } else {
            onOpen?.(transaction);
        }
    }

    const description = (
        <TransactionDescription
            text={transaction.description}
            encrypted={isEncrypted}
        />
    );

    return (
        <div
            {...props}
            onClick={handleRowClick}
            data-selected={selected || undefined}
            data-new={isNew || undefined}
            className={cn(
                LIST_ROW,
                'leading-[1.4] transition-colors',
                isInteractive && 'cursor-pointer select-none',
                isNew && 'border-l-2 border-l-blue-500 dark:border-l-blue-500',
                selected
                    ? 'bg-primary/5 dark:bg-primary/10'
                    : isNew
                      ? 'bg-blue-500/5 dark:bg-blue-500/10'
                      : isInteractive && 'hover:bg-muted/50',
                className,
            )}
            data-testid="transaction-list-row"
        >
            <SelectionCircle
                category={category}
                isSplit={splitLines.length > 0}
                selected={selected}
                isCategorizing={isCategorizing}
                label={__('Select :description', {
                    description: isEncrypted
                        ? ENCRYPTED_PLACEHOLDER
                        : transaction.description,
                })}
                onClick={
                    onToggleSelect &&
                    ((event) => {
                        event.stopPropagation();
                        onToggleSelect(transaction);
                    })
                }
            />

            <div
                // While selecting, a tap anywhere reaches the row and toggles it.
                inert={selectionActive}
                className={cn(
                    'flex min-w-0 flex-1 flex-col gap-0.5',
                    selectionActive && 'pointer-events-none',
                )}
            >
                <div className="flex items-baseline gap-2">
                    {onOpen ? (
                        // The row's keyboard entry point: the click bubbles
                        // to the row, which opens it.
                        <button
                            type="button"
                            className="min-w-0 flex-1 truncate rounded-sm text-left text-[15px] font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {description}
                        </button>
                    ) : (
                        <span className="min-w-0 flex-1 truncate text-[15px] font-medium">
                            {description}
                        </span>
                    )}
                    {badge && <TransactionBadge text={badge} />}
                    <AmountDisplay
                        amountInCents={transaction.amount}
                        currencyCode={transaction.currency_code}
                        variant="positive-highlight"
                        highlightPositive={transaction.amount > 0}
                        showSign
                        weight="medium"
                        monospace
                        // Cancels the px-1 `positive-highlight` gives every
                        // amount for its income pill, so the figure lines up
                        // with the row's edge; drop it with that variant.
                        className="-mr-1 shrink-0 text-[15px]"
                    />
                </div>

                <div
                    className={cn(
                        'flex min-w-0 items-center gap-1.5 overflow-hidden text-[13px] whitespace-nowrap',
                        COMPACT_LIST_MUTED_TEXT,
                    )}
                >
                    {splitLines.length > 0 ? (
                        <span className="shrink-0 font-medium">
                            {__('Split')} · {splitLines.length}
                        </span>
                    ) : (
                        <CategoryName
                            category={category}
                            isCategorizing={isCategorizing}
                        />
                    )}
                    {transaction.account && (
                        <>
                            <span aria-hidden>·</span>
                            <BankLogo
                                src={transaction.bank?.logo}
                                name={transaction.bank?.name}
                                fallback="letter"
                                className="size-3.5 shrink-0 rounded text-[8px]"
                            />
                            <AccountName
                                account={transaction.account}
                                className="min-w-8 truncate"
                            />
                        </>
                    )}
                </div>

                {splitLines.length > 0 && (
                    <SplitLines
                        lines={buildSplitBreakdown(
                            splitLines,
                            categoryFilterIds,
                        )}
                        currencyCode={transaction.currency_code}
                    />
                )}

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
                        {transaction.notes_iv
                            ? ENCRYPTED_PLACEHOLDER
                            : transaction.notes}
                    </span>
                )}

                {statementPayment && (
                    <StatementPaymentNote payment={statementPayment} />
                )}
            </div>
        </div>
    );
}

/** What a split transaction is filed under, as the table's category cell lists it. */
function SplitLines({
    lines,
    currencyCode,
}: {
    lines: SplitBreakdownLine[];
    currencyCode: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col gap-0.5 pt-0.5 text-[13px]',
                COMPACT_LIST_MUTED_TEXT,
            )}
        >
            {lines.map((line) => {
                const name = line.isRest
                    ? __('Rest')
                    : (line.category?.name ?? __('Unknown'));

                return (
                    <div
                        key={`${line.isRest ? 'rest' : (line.category?.id ?? 'unknown')}-${line.position}`}
                        className="flex min-w-0 items-center justify-between gap-2"
                    >
                        <span className="min-w-0 truncate">{name}</span>
                        <AmountDisplay
                            amountInCents={line.amount}
                            currencyCode={currencyCode}
                            showSign
                            size="xs"
                            className="shrink-0"
                        />
                    </div>
                );
            })}
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
    isSplit,
    selected,
    isCategorizing,
    label,
    onClick,
}: {
    category: Category | null;
    isSplit: boolean;
    selected: boolean;
    isCategorizing?: boolean;
    label: string;
    /** Without it the circle only shows the category: a picture, not a button. */
    onClick?: (event: MouseEvent<HTMLButtonElement>) => void;
}) {
    const circle = 'size-10 shrink-0 rounded-full';
    const box =
        '-m-0.5 flex size-11 shrink-0 items-center justify-center self-start rounded-full';
    const face = (
        <CircleFace
            category={category}
            isSplit={isSplit}
            selected={selected}
            isCategorizing={isCategorizing}
            circle={circle}
        />
    );

    if (!onClick) {
        return (
            <span
                aria-hidden
                className={box}
                data-testid="transaction-row-circle"
            >
                {face}
            </span>
        );
    }

    return (
        <button
            type="button"
            aria-pressed={selected}
            aria-label={label}
            onClick={onClick}
            className={cn(
                box,
                'outline-none focus-visible:ring-2 focus-visible:ring-ring',
            )}
            data-testid="transaction-row-circle"
        >
            {face}
        </button>
    );
}

function CircleFace({
    category,
    isSplit,
    selected,
    isCategorizing,
    circle,
}: {
    category: Category | null;
    isSplit: boolean;
    selected: boolean;
    isCategorizing?: boolean;
    circle: string;
}) {
    return selected ? (
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
    ) : isSplit ? (
        <span
            className={cn(
                circle,
                'flex items-center justify-center bg-muted text-muted-foreground',
            )}
        >
            <Split className="size-[18px]" />
        </span>
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
    );
}

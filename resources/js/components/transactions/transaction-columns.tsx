import { __ } from '@/utils/i18n';
import { ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';

import { BankLogo } from '@/components/bank-logo';
import { LabelBadges } from '@/components/shared/label-combobox';
import { CategoryCell } from '@/components/transactions/category-cell';
import { SplitOriginPopover } from '@/components/transactions/split-origin-popover';
import { StatementPaymentNote } from '@/components/transactions/statement-payment-note';
import { TransactionDescription } from '@/components/transactions/transaction-description';
import { TransactionRowDropdownMenuContent } from '@/components/transactions/transaction-row-dropdown-menu';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { resolveTransactionLabels } from '@/lib/transaction-labels';
import { getTransactionRowActions } from '@/lib/transaction-row-actions';
import { isSplitPart } from '@/lib/transaction-splits';
import {
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type ServerTransaction } from '@/types/transaction';

interface CreateColumnsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    onEdit: (transaction: ServerTransaction) => void;
    onDuplicate: (transaction: ServerTransaction) => void;
    onDelete: (transaction: ServerTransaction) => void;
    onUpdate: (transaction: ServerTransaction) => void;
    onCategorized?: (
        transaction: ServerTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    onReEvaluateRules: (transaction: ServerTransaction) => void;
    onAutomate: (transaction: ServerTransaction) => void;
    onSplit: (transaction: ServerTransaction) => void;
    onUnsplit: (transaction: ServerTransaction) => void;
    /** Ids of transactions AI is categorizing in the background right now. */
    categorizingIds?: Set<string>;
    /** A label every row already carries, so showing it would say nothing. */
    hiddenLabelId?: string;
    /**
     * Credit card repayments by transaction id, with the statement each one
     * pays. Only the credit card account page passes it.
     */
    statementPayments?: Map<string, CreditCardPayment>;
}

export function createTransactionColumns({
    categories,
    accounts,
    banks,
    labels,
    onEdit,
    onDuplicate,
    onDelete,
    onUpdate,
    onCategorized,
    onReEvaluateRules,
    onAutomate,
    onSplit,
    onUnsplit,
    categorizingIds,
    hiddenLabelId,
    statementPayments,
}: CreateColumnsOptions): ColumnDef<ServerTransaction>[] {
    return [
        {
            id: 'select',
            header: ({ table }) => (
                <Checkbox
                    checked={
                        table.getIsAllPageRowsSelected() ||
                        (table.getIsSomePageRowsSelected() && 'indeterminate')
                    }
                    className="ml-2"
                    onCheckedChange={(value) =>
                        table.toggleAllPageRowsSelected(!!value)
                    }
                    aria-label={__('Select all')}
                />
            ),

            cell: ({ row }) => (
                <Checkbox
                    checked={row.getIsSelected()}
                    className="ml-2"
                    onCheckedChange={(value) => row.toggleSelected(!!value)}
                    onClick={(e) => e.stopPropagation()}
                    aria-label={__('Select row')}
                />
            ),

            enableHiding: false,
        },
        {
            accessorKey: 'category_id',
            meta: {
                label: __('Category'),
                cellClassName:
                    'pl-2 max-w-[170px] !sm:max-w-[170px] md:max-w-[190px] !min-w-[170px] whitespace-normal',
            },
            header: () => __('Category'),
            cell: ({ row }) => {
                return (
                    <CategoryCell
                        transaction={row.original}
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        onUpdate={onUpdate}
                        onCategorized={onCategorized}
                        className="relative -top-0.5 max-w-[150px] md:max-w-[180px]"
                        withoutChevronIcon
                        isCategorizing={categorizingIds?.has(row.original.id)}
                    />
                );
            },
            enableHiding: true,
        },
        {
            id: 'account',
            accessorKey: 'account',
            header: () => __('Account'),
            meta: {
                label: __('Account'),
                cellClassName: '!min-w-[125px] whitespace-normal',
            },
            cell: ({ row }) => {
                const transaction = row.original;
                if (!transaction.account) {
                    return <div className="text-muted-foreground">—</div>;
                }

                return (
                    <div className="flex items-center gap-2">
                        <BankLogo
                            src={transaction.bank?.logo}
                            name={transaction.bank?.name}
                            className="h-5 w-5"
                        />
                        <span className="truncate">
                            {transaction.account.name}
                        </span>
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'description',
            meta: {
                label: __('Description'),
                cellClassName:
                    'max-w-[200px] sm:max-w-[250px] md:max-w-[300px] md:w-full md:min-w-0 md:overflow-hidden whitespace-normal',
            },
            header: () => __('Description'),
            cell: ({ row, table }) => {
                const transaction = row.original;
                const columnVisibility = table.getState().columnVisibility;

                const showLabels = columnVisibility.labels !== false;
                const showNotes = columnVisibility.notes !== false;

                const transactionLabels = resolveTransactionLabels(
                    transaction,
                    labels,
                    hiddenLabelId,
                );

                const hasLabels = transactionLabels.length > 0;
                const hasNotes = !!transaction.notes;
                const statementPayment = statementPayments?.get(transaction.id);

                return (
                    // The split mark sits in its own gutter, so everything
                    // beside it — description, labels, notes — lines up exactly
                    // as it does on a row that was never split.
                    <div className="flex gap-1.5">
                        {isSplitPart(transaction) && (
                            <SplitOriginPopover
                                transaction={transaction}
                                categories={categories}
                                onUnsplit={onUnsplit}
                            />
                        )}
                        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <div className="flex flex-row justify-between gap-1">
                                <div className="flex-grow truncate">
                                    <TransactionDescription
                                        text={transaction.description}
                                    />
                                </div>
                                {showLabels && hasLabels && (
                                    <LabelBadges
                                        labels={transactionLabels}
                                        max={3}
                                    />
                                )}
                            </div>
                            {statementPayment && (
                                <StatementPaymentNote
                                    payment={statementPayment}
                                />
                            )}
                            {showNotes && hasNotes && (
                                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                    <div className="truncate text-muted-foreground/80">
                                        <span>{transaction.notes}</span>
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                );
            },
            enableHiding: false,
        },
        {
            accessorKey: 'creditor_name',
            meta: {
                label: __('Creditor'),
                cellClassName: 'max-w-[180px] whitespace-normal',
            },
            header: () => __('Creditor'),
            cell: ({ row }) => {
                const creditorName = row.original.creditor_name;

                return creditorName ? (
                    <div className="truncate">{creditorName}</div>
                ) : (
                    <div className="text-muted-foreground">—</div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'debtor_name',
            meta: {
                label: __('Debtor'),
                cellClassName: 'max-w-[180px] whitespace-normal',
            },
            header: () => __('Debtor'),
            cell: ({ row }) => {
                const debtorName = row.original.debtor_name;

                return debtorName ? (
                    <div className="truncate">{debtorName}</div>
                ) : (
                    <div className="text-muted-foreground">—</div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'amount',
            meta: { label: __('Amount') },
            header: () => {
                return <div className="w-full text-right">{__('Amount')}</div>;
            },
            cell: ({ row }) => {
                const amountInCents = row.getValue('amount') as number;
                const currencyCode = row.original.currency_code;

                return (
                    <div className="pl-4 text-right">
                        <AmountDisplay
                            amountInCents={amountInCents}
                            currencyCode={currencyCode}
                            variant="positive-highlight"
                            highlightPositive={amountInCents >= 0}
                            monospace
                        />
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            id: 'actions',
            enableHiding: false,
            meta: {
                cellClassName:
                    '!w-[45px] !max-w-[45px] !min-w-[45px] whitespace-normal',
            },
            cell: ({ row }) => {
                const transaction = row.original;

                return (
                    <div className="relative -top-0.5 flex w-[35px] items-center justify-center">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="h-[24px] w-8 p-0"
                                    onClick={(e) => e.stopPropagation()}
                                >
                                    <span className="sr-only">
                                        {__('Open menu')}
                                    </span>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <TransactionRowDropdownMenuContent
                                align="end"
                                actions={getTransactionRowActions({
                                    transaction,
                                    onEdit,
                                    onDuplicate,
                                    onReEvaluateRules,
                                    onAutomate,
                                    onDelete,
                                    onSplit,
                                    onUnsplit,
                                })}
                            />
                        </DropdownMenu>
                    </div>
                );
            },
        },
        // Virtual columns for visibility control only (not rendered as table columns)
        {
            id: 'labels',
            accessorKey: 'label_ids',
            meta: { label: __('Labels'), isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
        {
            id: 'notes',
            accessorKey: 'notes',
            meta: { label: __('Notes'), isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
    ];
}

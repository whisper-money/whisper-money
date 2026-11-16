import { __ } from '@/utils/i18n';
import { ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';

import { CategoryCell } from '@/components/transactions/category-cell';
import {
    accountColumn,
    amountColumn,
    categoryColumn,
    descriptionColumn,
    type DisplayRow,
    visibilityColumns,
} from '@/components/transactions/transaction-base-columns';
import { TransactionRowDropdownMenuContent } from '@/components/transactions/transaction-row-dropdown-menu';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { getTransactionRowActions } from '@/lib/transaction-row-actions';
import {
    type Account,
    type Bank,
    type CreditCardPayment,
} from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';

interface CreateColumnsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    onEdit: (transaction: DecryptedTransaction) => void;
    onDuplicate: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onUpdate: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
    onAutomate: (transaction: DecryptedTransaction) => void;
    /** Ids of transactions AI is categorizing in the background right now. */
    categorizingIds?: Set<string>;
    categoryFilterIds?: ReadonlySet<string>;
    /** A label every row already carries, so showing it would say nothing. */
    hiddenLabelId?: string;
    /**
     * Credit card repayments by transaction id, with the statement each one
     * pays. Only the credit card account page passes it.
     */
    statementPayments?: Map<string, CreditCardPayment>;
    /** How each row reads, apart from what it holds. */
    displayRow?: DisplayRow;
    /** A short tag after a row's description, or nothing. */
    badgeOf?: (transaction: DecryptedTransaction) => string | null | undefined;
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
    categorizingIds,
    categoryFilterIds,
    hiddenLabelId,
    statementPayments,
    displayRow,
    badgeOf,
}: CreateColumnsOptions): ColumnDef<DecryptedTransaction>[] {
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
        categoryColumn((transaction) => (
            <CategoryCell
                transaction={transaction}
                categories={categories}
                accounts={accounts}
                banks={banks}
                onUpdate={onUpdate}
                onCategorized={onCategorized}
                onEdit={onEdit}
                className="relative -top-0.5 max-w-[150px] md:max-w-[180px]"
                withoutChevronIcon
                isCategorizing={categorizingIds?.has(transaction.id)}
                categoryFilterIds={categoryFilterIds}
            />
        )),
        accountColumn(displayRow),
        descriptionColumn({
            labels,
            hiddenLabelId,
            statementPayments,
            displayRow,
            badgeOf,
        }),
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
        amountColumn(),
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
                                })}
                            />
                        </DropdownMenu>
                    </div>
                );
            },
        },
        ...visibilityColumns(),
    ];
}

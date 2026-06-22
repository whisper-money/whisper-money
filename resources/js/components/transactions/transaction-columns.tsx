import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { ColumnDef } from '@tanstack/react-table';
import { getYear, parseISO } from 'date-fns';
import { ArrowDown, ArrowUp, ArrowUpDown, MoreHorizontal } from 'lucide-react';

import { AccountName } from '@/components/accounts/account-name';
import { BankLogo } from '@/components/bank-logo';
import { LabelBadges } from '@/components/shared/label-combobox';
import { CategoryCell } from '@/components/transactions/category-cell';
import {
    ENCRYPTED_PLACEHOLDER,
    TransactionDescription,
} from '@/components/transactions/transaction-description';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { type DecryptedTransaction } from '@/types/transaction';

interface CreateColumnsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    locale: string;
    onEdit: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onUpdate: (transaction: DecryptedTransaction) => void;
    onCategorized?: (
        transaction: DecryptedTransaction,
        category: Category,
        source: 'transaction_table',
    ) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
}

export function createTransactionColumns({
    categories,
    accounts,
    banks,
    labels,
    locale,
    onEdit,
    onDelete,
    onUpdate,
    onCategorized,
    onReEvaluateRules,
}: CreateColumnsOptions): ColumnDef<DecryptedTransaction>[] {
    return [
        {
            id: 'select',
            meta: {
                cellClassName: 'hidden md:table-cell',
            },
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

            enableSorting: false,
            enableHiding: false,
        },
        {
            id: 'transaction_date',
            accessorKey: 'transaction_date',
            meta: {
                label: __('Date'),
                cellClassName: 'max-w-[90px] whitespace-normal pr-1',
            },
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        className="px-0"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        {__('Date')}
                        {column.getIsSorted() === 'desc' ? (
                            <ArrowDown className="h-3 w-3" />
                        ) : column.getIsSorted() === 'asc' ? (
                            <ArrowUp className="h-3 w-3" />
                        ) : (
                            <ArrowUpDown className="h-3 w-3 opacity-25" />
                        )}
                    </Button>
                );
            },
            cell: ({ row }) => {
                const date = parseISO(row.getValue('transaction_date'));
                const currentYear = getYear(new Date());
                const transactionYear = getYear(date);
                const formatString =
                    transactionYear === currentYear ? 'MMM d' : 'MMM d, yy';

                const formatted = formatDate(date, formatString, locale);
                // Capitalize first letter (important for Spanish dates)
                const capitalized =
                    formatted.charAt(0).toUpperCase() + formatted.slice(1);

                return (
                    <div className="pl-3 whitespace-nowrap">{capitalized}</div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'category_id',
            meta: {
                label: __('Category'),
                cellClassName:
                    'pl-0 first:pl-2 max-w-[170px] !sm:max-w-[170px] md:max-w-[190px] !min-w-[170px] whitespace-normal',
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
                        <AccountName
                            account={transaction.account}
                            length={{ min: 5, max: 15 }}
                            className="truncate"
                        />
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'decryptedDescription',
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

                const transactionLabels = (transaction.label_ids || [])
                    .map((id) => labels.find((l) => l.id === id))
                    .filter(Boolean) as Label[];

                const hasLabels = transactionLabels.length > 0;
                const hasNotes = !!transaction.notes;

                return (
                    <div className="flex flex-col gap-0.5">
                        <div className="flex flex-row justify-between gap-1">
                            <div className="flex-grow truncate">
                                <TransactionDescription
                                    text={transaction.description}
                                    encrypted={!!transaction.description_iv}
                                />
                            </div>
                            {showLabels && hasLabels && (
                                <LabelBadges
                                    labels={transactionLabels}
                                    max={3}
                                />
                            )}
                        </div>
                        {showNotes && hasNotes && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground">
                                <div className="truncate text-muted-foreground/80">
                                    <span>
                                        {transaction.notes_iv
                                            ? ENCRYPTED_PLACEHOLDER
                                            : transaction.notes}
                                    </span>
                                </div>
                            </div>
                        )}
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
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        {__('Creditor')}
                        {column.getIsSorted() === 'desc' ? (
                            <ArrowDown className="h-3 w-3" />
                        ) : column.getIsSorted() === 'asc' ? (
                            <ArrowUp className="h-3 w-3" />
                        ) : (
                            <ArrowUpDown className="h-3 w-3 opacity-25" />
                        )}
                    </Button>
                );
            },
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
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        {__('Debtor')}
                        {column.getIsSorted() === 'desc' ? (
                            <ArrowDown className="h-3 w-3" />
                        ) : column.getIsSorted() === 'asc' ? (
                            <ArrowUp className="h-3 w-3" />
                        ) : (
                            <ArrowUpDown className="h-3 w-3 opacity-25" />
                        )}
                    </Button>
                );
            },
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
                const amount = amountInCents / 100;
                const currencyCode = row.original.currency_code;

                return (
                    <div className="pl-4 text-right">
                        <AmountDisplay
                            amountInCents={amountInCents}
                            currencyCode={currencyCode}
                            variant="positive-highlight"
                            highlightPositive={amount >= 0}
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
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>
                                    {__('Actions')}
                                </DropdownMenuLabel>
                                <DropdownMenuItem
                                    onClick={() => onEdit(transaction)}
                                >
                                    {__('Edit')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() =>
                                        onReEvaluateRules(transaction)
                                    }
                                >
                                    {__('Re-evaluate rules')}
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => onDelete(transaction)}
                                >
                                    {__('Delete')}
                                </DropdownMenuItem>
                            </DropdownMenuContent>
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
            accessorKey: 'decryptedNotes',
            meta: { label: __('Notes'), isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
    ];
}

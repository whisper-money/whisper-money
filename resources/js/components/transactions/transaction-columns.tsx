import { ColumnDef } from '@tanstack/react-table';
import { format, getYear, parseISO } from 'date-fns';
import { ArrowDown, MoreHorizontal } from 'lucide-react';

import { EncryptedText } from '@/components/encrypted-text';
import { LabelBadges } from '@/components/shared/label-combobox';
import { CategoryCell } from '@/components/transactions/category-cell';
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
    onEdit: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onUpdate: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
}

export function createTransactionColumns({
    categories,
    accounts,
    banks,
    labels,
    onEdit,
    onDelete,
    onUpdate,
    onReEvaluateRules,
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
                    aria-label="Select all"
                />
            ),
            cell: ({ row }) => (
                <Checkbox
                    checked={row.getIsSelected()}
                    className="ml-2"
                    onCheckedChange={(value) => row.toggleSelected(!!value)}
                    onClick={(e) => e.stopPropagation()}
                    aria-label="Select row"
                />
            ),
            enableSorting: false,
            enableHiding: false,
        },
        {
            id: 'transaction_date',
            accessorKey: 'transaction_date',
            meta: {
                label: 'Date',
                cellClassName: 'max-w-[80px] whitespace-normal',
            },
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        Date
                        <ArrowDown className="h-2 w-2 opacity-25" />
                    </Button>
                );
            },
            cell: ({ row }) => {
                const date = parseISO(row.getValue('transaction_date'));
                const currentYear = getYear(new Date());
                const transactionYear = getYear(date);
                const formatString =
                    transactionYear === currentYear ? 'MMM d' : 'MMM d, yy';

                return <div className="pl-3">{format(date, formatString)}</div>;
            },
            enableHiding: true,
        },
        {
            accessorKey: 'category_id',
            meta: {
                label: 'Category',
                cellClassName:
                    'max-w-[170px] !sm:max-w-[170px] md:max-w-[190px] !min-w-[170px] whitespace-normal',
            },
            header: 'Category',
            cell: ({ row }) => {
                return (
                    <CategoryCell
                        transaction={row.original}
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        onUpdate={onUpdate}
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
            header: 'Account',
            meta: {
                label: 'Account',
                cellClassName: '!min-w-[125px] whitespace-normal',
            },
            cell: ({ row }) => {
                const transaction = row.original;
                if (!transaction.account) {
                    return <div className="text-muted-foreground">—</div>;
                }

                return (
                    <div className="flex items-center gap-2">
                        {transaction.bank?.logo && (
                            <img
                                src={transaction.bank.logo}
                                alt={transaction.bank.name}
                                className="h-5 w-5 rounded-full"
                            />
                        )}
                        <EncryptedText
                            encryptedText={transaction.account.name}
                            iv={transaction.account.name_iv}
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
                label: 'Description',
                cellClassName:
                    'max-w-[200px] sm:max-w-[400px] md:max-w-[400px] lg:max-w-[550px] xl:max-w-full xl:w-full',
            },
            header: 'Description',
            cell: ({ row, table }) => {
                const transaction = row.original;
                const columnVisibility = table.getState().columnVisibility;

                const showLabels = columnVisibility.labels !== false;
                const showNotes = columnVisibility.notes !== false;

                const transactionLabels = (transaction.label_ids || [])
                    .map((id) => labels.find((l) => l.id === id))
                    .filter(Boolean) as Label[];

                const hasLabels = transactionLabels.length > 0;
                const hasNotes =
                    transaction.decryptedNotes ||
                    (transaction.notes && transaction.notes_iv);

                return (
                    <div className="flex flex-col gap-0.5">
                        <div className="flex flex-row justify-between gap-1">
                            <div className="flex-grow truncate">
                                <EncryptedText
                                    encryptedText={transaction.description}
                                    iv={transaction.description_iv}
                                    length={{ min: 20, max: 80 }}
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
                                    {transaction.decryptedNotes ? (
                                        <span>
                                            {transaction.decryptedNotes}
                                        </span>
                                    ) : (
                                        <EncryptedText
                                            encryptedText={
                                                transaction.notes || ''
                                            }
                                            iv={transaction.notes_iv || ''}
                                            length={{ min: 10, max: 30 }}
                                        />
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                );
            },
            enableHiding: false,
        },
        {
            accessorKey: 'amount',
            meta: { label: 'Amount' },
            header: () => {
                return <div className="w-full text-right">Amount</div>;
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
                                    <span className="sr-only">Open menu</span>
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuLabel>Actions</DropdownMenuLabel>
                                <DropdownMenuItem
                                    onClick={() => onEdit(transaction)}
                                >
                                    Edit
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    onClick={() =>
                                        onReEvaluateRules(transaction)
                                    }
                                >
                                    Re-evaluate rules
                                </DropdownMenuItem>
                                <DropdownMenuItem
                                    variant="destructive"
                                    onClick={() => onDelete(transaction)}
                                >
                                    Delete
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
            meta: { label: 'Labels', isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
        {
            id: 'notes',
            accessorKey: 'decryptedNotes',
            meta: { label: 'Notes', isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
    ];
}

import { ColumnDef } from '@tanstack/react-table';
import { MoreHorizontal } from 'lucide-react';

import { EncryptedText } from '@/components/encrypted-text';
import { LabelBadges } from '@/components/shared/label-combobox';
import { CategoryCell } from '@/components/transactions/category-cell';
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
                    onCheckedChange={(value) =>
                        table.toggleAllPageRowsSelected(!!value)
                    }
                    aria-label="Select all"
                />
            ),
            cell: ({ row }) => (
                <Checkbox
                    checked={row.getIsSelected()}
                    onCheckedChange={(value) => row.toggleSelected(!!value)}
                    onClick={(e) => e.stopPropagation()}
                    aria-label="Select row"
                />
            ),
            enableSorting: false,
            enableHiding: false,
        },
        {
            accessorKey: 'category_id',
            meta: { label: 'Category' },
            header: 'Category',
            cell: ({ row }) => {
                return (
                    <div className="max-w-[200px]">
                        <CategoryCell
                            transaction={row.original}
                            categories={categories}
                            accounts={accounts}
                            banks={banks}
                            onUpdate={onUpdate}
                        />
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'decryptedDescription',
            meta: { label: 'Description' },
            header: 'Description',
            cell: ({ row, table }) => {
                const transaction = row.original;
                const columnVisibility = table.getState().columnVisibility;

                const showAccount = columnVisibility.account !== false;
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
                    <div className="flex flex-col gap-1 py-1">
                        <div className="truncate font-medium">
                            <EncryptedText
                                encryptedText={transaction.description}
                                iv={transaction.description_iv}
                                length={{ min: 20, max: 80 }}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                            {showAccount && transaction.account && (
                                <div className="flex items-center gap-1.5">
                                    {transaction.bank?.logo && (
                                        <img
                                            src={transaction.bank.logo}
                                            alt={transaction.bank.name}
                                            className="h-4 w-4 rounded-full"
                                        />
                                    )}
                                    <EncryptedText
                                        encryptedText={transaction.account.name}
                                        iv={transaction.account.name_iv}
                                        length={{ min: 5, max: 15 }}
                                        className="truncate"
                                    />
                                </div>
                            )}
                            {showLabels && hasLabels && (
                                <LabelBadges
                                    labels={transactionLabels}
                                    max={3}
                                />
                            )}
                            {showNotes && hasNotes && (
                                <div className="max-w-[200px] truncate text-muted-foreground/70 italic">
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
                            )}
                        </div>
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

                const formatted = new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: currencyCode,
                }).format(amount);

                return (
                    <div className={`pl-4 text-right`}>
                        <span
                            className={`${amount < 0 ? '' : 'bg-green-100/70 dark:bg-green-900'}`}
                        >
                            {formatted}
                        </span>
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            id: 'actions',
            enableHiding: false,
            size: 35,
            maxSize: 35,
            minSize: 35,
            meta: {
                cellClassName:
                    '!w-[35px] !max-w-[35px] !min-w-[35px] !p-0 whitespace-normal',
                cellStyle: {
                    width: '35px',
                    maxWidth: '35px',
                    minWidth: '35px',
                    padding: 0,
                },
            },
            cell: ({ row }) => {
                const transaction = row.original;

                return (
                    <div className="flex w-[35px] items-center justify-center">
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="ghost"
                                    className="h-8 w-8 p-0"
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
            id: 'transaction_date',
            accessorKey: 'transaction_date',
            meta: { label: 'Date', isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
        {
            id: 'account',
            accessorKey: 'account',
            meta: { label: 'Account', isVirtual: true },
            header: () => null,
            cell: () => null,
            enableHiding: true,
        },
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

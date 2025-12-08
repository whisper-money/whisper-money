import { ColumnDef } from '@tanstack/react-table';
import { format, getYear, parseISO } from 'date-fns';
import { ArrowDown, MoreHorizontal } from 'lucide-react';

import { EncryptedText } from '@/components/encrypted-text';
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
import { type DecryptedTransaction } from '@/types/transaction';

interface CreateColumnsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onEdit: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onUpdate: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
}

export function createTransactionColumns({
    categories,
    accounts,
    banks,
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
            accessorKey: 'transaction_date',
            meta: { label: 'Date' },
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
                    transactionYear === currentYear ? 'MMM d' : 'MMM d, yyyy';

                return (
                    <div className="pl-3 font-medium">
                        {format(date, formatString)}
                    </div>
                );
            },
            enableHiding: true,
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
        },
        {
            accessorKey: 'decryptedDescription',
            meta: { label: 'Description' },
            header: 'Description',
            cell: ({ row }) => {
                const transaction = row.original;
                return (
                    <div className="max-w-[150px] truncate md:max-w-[250px] lg:max-w-[400px]">
                        <EncryptedText
                            encryptedText={transaction.description}
                            iv={transaction.description_iv}
                            length={{ min: 20, max: 80 }}
                        />
                    </div>
                );
            },
        },
        {
            accessorKey: 'bank',
            meta: { label: 'Bank' },
            header: 'Bank',
            cell: ({ row }) => {
                const bank = row.original.bank;
                return (
                    <div className="flex items-center gap-2 px-1">
                        <span>{bank?.name || 'N/A'}</span>
                    </div>
                );
            },
            enableHiding: true,
        },
        {
            accessorKey: 'account',
            meta: { label: 'Account' },
            header: 'Account',
            cell: ({ row }) => {
                const bank = row.original.bank;
                const account = row.original.account;
                if (!account) {
                    return <div className="px-1">N/A</div>;
                }

                return (
                    <div className="flex min-w-[140px] items-center gap-2 px-1">
                        {bank?.logo && (
                            <img
                                src={bank.logo}
                                alt={bank.name}
                                className="h-6 w-6 rounded-full"
                            />
                        )}
                        <EncryptedText
                            encryptedText={account.name}
                            iv={account.name_iv}
                            length={{ min: 5, max: 10 }}
                            className="w-full truncate px-1"
                        />
                    </div>
                );
            },
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
    ];
}

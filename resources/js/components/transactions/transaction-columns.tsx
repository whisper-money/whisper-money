import { ColumnDef } from '@tanstack/react-table';
import { ArrowDown, ArrowUpDown, MoreHorizontal, Pencil, Trash2 } from 'lucide-react';
import { format, parseISO } from 'date-fns';

import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { CategoryCell } from '@/components/transactions/category-cell';
import { EncryptedText } from '@/components/encrypted-text';
import { type DecryptedTransaction } from '@/types/transaction';
import { type Category } from '@/types/category';
import { type Account, type Bank } from '@/types/account';

interface CreateColumnsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onEdit: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
    onUpdate: (transaction: DecryptedTransaction) => void;
}

export function createTransactionColumns({
    categories,
    accounts,
    banks,
    onEdit,
    onDelete,
    onUpdate,
}: CreateColumnsOptions): ColumnDef<DecryptedTransaction>[] {
    return [
        {
            accessorKey: 'transaction_date',
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        Date
                        <ArrowDown className="ml-2 h-4 w-4 opacity-50" />
                    </Button>
                );
            },
            cell: ({ row }) => {
                return (
                    <div className="font-medium pl-3">
                        {format(parseISO(row.getValue('transaction_date')), 'PP')}
                    </div>
                );
            },
        },
        {
            accessorKey: 'category_id',
            header: 'Category',
            cell: ({ row }) => {
                return (
                    <CategoryCell
                        transaction={row.original}
                        categories={categories}
                        accounts={accounts}
                        banks={banks}
                        onUpdate={onUpdate}
                    />
                );
            },
        },
        {
            accessorKey: 'decryptedDescription',
            header: 'Description',
            cell: ({ row }) => {
                const transaction = row.original;
                return (
                    <div className="max-w-[150px] md:max-w-[250px]  lg:max-w-[500px] truncate">
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
            header: 'Bank',
            cell: ({ row }) => {
                const bank = row.original.bank;
                return (
                    <div className="flex items-center gap-2">
                        {bank?.logo && (
                            <img
                                src={bank.logo}
                                alt={bank.name}
                                className="h-6 w-6 rounded"
                            />
                        )}
                        <span>{bank?.name || 'N/A'}</span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'account',
            header: 'Account',
            cell: ({ row }) => {
                const account = row.original.account;
                return <div>{account?.name || 'N/A'}</div>;
            },
            enableHiding: true,
        },
        {
            accessorKey: 'amount',
            header: () => {
                return (
                    <div className="w-full text-right">
                        Amount
                    </div>
                );
            },
            cell: ({ row }) => {
                const amount = parseFloat(row.getValue('amount'));
                const currencyCode = row.original.currency_code;

                const formatted = new Intl.NumberFormat('en-US', {
                    style: 'currency',
                    currency: currencyCode,
                }).format(amount);

                return (
                    <div
                        className={`text-right`}
                    >
                        <span className={`${amount < 0 ? '' : 'bg-green-100/70 dark:bg-green-900'}`}>{formatted}</span>
                    </div>
                );
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => {
                const transaction = row.original;

                return (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button variant="ghost" className="h-8 w-8 p-0">
                                <span className="sr-only">Open menu</span>
                                <MoreHorizontal className="h-4 w-4" />
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end">
                            <DropdownMenuLabel>Actions</DropdownMenuLabel>
                            <DropdownMenuItem onClick={() => onEdit(transaction)}>
                                Edit
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                variant='destructive'
                                onClick={() => onDelete(transaction)}
                            >
                                Delete
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                );
            },
        },
    ];
}


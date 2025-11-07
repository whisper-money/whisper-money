import { useState, useEffect } from 'react';
import { Head } from '@inertiajs/react';
import {
    ColumnDef,
    ColumnFiltersState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getPaginationRowModel,
    getSortedRowModel,
    SortingState,
    useReactTable,
    VisibilityState,
} from '@tanstack/react-table';
import { ArrowUpDown, MoreHorizontal } from 'lucide-react';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Badge } from '@/components/ui/badge';
import HeadingSmall from '@/components/heading-small';
import { CreateAccountDialog } from '@/components/accounts/create-account-dialog';
import { EditAccountDialog } from '@/components/accounts/edit-account-dialog';
import { DeleteAccountDialog } from '@/components/accounts/delete-account-dialog';
import {
    type Account,
    type Bank,
    formatAccountType,
} from '@/types/account';
import { type BreadcrumbItem } from '@/types';
import { index as accountsIndex } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { getStoredKey } from '@/lib/key-storage';
import { importKey, decrypt } from '@/lib/crypto';

interface AccountsProps {
    accounts: Account[];
    banks: Bank[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Accounts settings',
        href: accountsIndex(),
    },
];

function AccountActions({ account }: { account: Account }) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="h-8 w-8 p-0">
                        <span className="sr-only">Open menu</span>
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>Actions</DropdownMenuLabel>
                    <DropdownMenuItem onClick={() => setEditOpen(true)}>
                        Edit
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => setDeleteOpen(true)}
                        className="text-red-600"
                    >
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditAccountDialog
                account={account}
                open={editOpen}
                onOpenChange={setEditOpen}
            />
            <DeleteAccountDialog
                account={account}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />
        </>
    );
}

export default function Accounts({ accounts, banks }: AccountsProps) {
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );
    const [decryptedNames, setDecryptedNames] = useState<
        Record<number, string>
    >({});

    useEffect(() => {
        async function decryptAccountNames() {
            const keyString = getStoredKey();
            if (!keyString) return;

            try {
                const key = await importKey(keyString);
                const decrypted: Record<number, string> = {};

                for (const account of accounts) {
                    try {
                        const name = await decrypt(
                            account.name,
                            key,
                            account.name_iv,
                        );
                        decrypted[account.id] = name;
                    } catch (err) {
                        console.error(
                            `Failed to decrypt account ${account.id}:`,
                            err,
                        );
                        decrypted[account.id] = '[Encrypted]';
                    }
                }

                setDecryptedNames(decrypted);
            } catch (err) {
                console.error('Failed to decrypt account names:', err);
            }
        }

        decryptAccountNames();
    }, [accounts]);

    const columns: ColumnDef<Account>[] = [
        {
            accessorKey: 'name',
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        Name
                        <ArrowUpDown className="ml-2 h-4 w-4" />
                    </Button>
                );
            },
            cell: ({ row }) => {
                const name = decryptedNames[row.original.id] || '[Encrypted]';
                return <div className="pl-3 font-medium">{name}</div>;
            },
        },
        {
            accessorKey: 'bank',
            header: 'Bank',
            cell: ({ row }) => {
                const bank = row.original.bank;
                return (
                    <div className="flex items-center gap-2">
                        {bank.logo ? (
                            <img
                                src={bank.logo}
                                alt={bank.name}
                                className="h-6 w-6 rounded object-contain"
                            />
                        ) : (
                            <div className="h-6 w-6 rounded bg-muted" />
                        )}
                        <span>{bank.name}</span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'type',
            header: 'Type',
            cell: ({ row }) => {
                return (
                    <Badge variant="outline">
                        {formatAccountType(row.getValue('type'))}
                    </Badge>
                );
            },
        },
        {
            accessorKey: 'currency_code',
            header: 'Currency',
            cell: ({ row }) => {
                return (
                    <div className="font-medium">
                        {row.getValue('currency_code')}
                    </div>
                );
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => <AccountActions account={row.original} />,
        },
    ];

    const table = useReactTable({
        data: accounts,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        getCoreRowModel: getCoreRowModel(),
        getPaginationRowModel: getPaginationRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        state: {
            sorting,
            columnFilters,
            columnVisibility,
        },
    });

    return (
        <AppLayout
            breadcrumbs={breadcrumbs}
            header={
                <h2 className="text-xl font-semibold leading-tight text-gray-800 dark:text-gray-200">
                    Accounts
                </h2>
            }
        >
            <Head title="Accounts settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Accounts settings"
                        description="Manage your bank accounts"
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Input
                                placeholder="Filter accounts..."
                                value={
                                    (table
                                        .getColumn('name')
                                        ?.getFilterValue() as string) ?? ''
                                }
                                onChange={(event) =>
                                    table
                                        .getColumn('name')
                                        ?.setFilterValue(event.target.value)
                                }
                                className="max-w-sm"
                            />
                            <CreateAccountDialog banks={banks} />
                        </div>

                        <div className="rounded-md border">
                            <Table>
                                <TableHeader>
                                    {table.getHeaderGroups().map((headerGroup) => (
                                        <TableRow key={headerGroup.id}>
                                            {headerGroup.headers.map((header) => {
                                                return (
                                                    <TableHead key={header.id}>
                                                        {header.isPlaceholder
                                                            ? null
                                                            : flexRender(
                                                                  header.column
                                                                      .columnDef
                                                                      .header,
                                                                  header.getContext(),
                                                              )}
                                                    </TableHead>
                                                );
                                            })}
                                        </TableRow>
                                    ))}
                                </TableHeader>
                                <TableBody>
                                    {table.getRowModel().rows?.length ? (
                                        table.getRowModel().rows.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                data-state={
                                                    row.getIsSelected() && 'selected'
                                                }
                                            >
                                                {row.getVisibleCells().map((cell) => (
                                                    <TableCell key={cell.id}>
                                                        {flexRender(
                                                            cell.column.columnDef.cell,
                                                            cell.getContext(),
                                                        )}
                                                    </TableCell>
                                                ))}
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={columns.length}
                                                className="h-24 text-center"
                                            >
                                                No accounts found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}


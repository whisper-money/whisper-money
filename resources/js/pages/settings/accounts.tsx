import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import {
    Cell,
    ColumnDef,
    ColumnFiltersState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    Row,
    SortingState,
    useReactTable,
    VisibilityState,
} from '@tanstack/react-table';
import { ArrowUpDown, Link2, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';

import { index as accountsIndex } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { AccountName } from '@/components/accounts/account-name';
import { CreateAccountDialog } from '@/components/accounts/create-account-dialog';
import { DeleteAccountDialog } from '@/components/accounts/delete-account-dialog';
import { EditAccountDialog } from '@/components/accounts/edit-account-dialog';
import { BankLogo } from '@/components/bank-logo';
import HeadingSmall from '@/components/heading-small';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { type Account, formatAccountType } from '@/types/account';

function AccountActions({
    account,
    onSuccess,
}: {
    account: Account;
    onSuccess?: () => void;
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="h-8 w-8 p-0"
                        aria-label={__('Open menu')}
                    >
                        <span className="sr-only">{__('Open menu')}</span>
                        <MoreHorizontal className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuLabel>{__('Actions')}</DropdownMenuLabel>
                    <DropdownMenuItem onClick={() => setEditOpen(true)}>
                        {__('Edit')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => setDeleteOpen(true)}
                        className="text-red-600"
                    >
                        {__('Delete')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditAccountDialog
                account={account}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={onSuccess}
            />

            <DeleteAccountDialog
                account={account}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={onSuccess}
            />
        </>
    );
}

function AccountRow({
    row,
    onSuccess,
}: {
    row: Row<Account>;
    onSuccess?: () => void;
}) {
    const account = row.original;
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [contextMenuOpen, setContextMenuOpen] = useState(false);

    return (
        <>
            <ContextMenu onOpenChange={setContextMenuOpen}>
                <ContextMenuTrigger asChild>
                    <TableRow
                        data-state={
                            (row.getIsSelected() || contextMenuOpen) &&
                            'selected'
                        }
                    >
                        {row
                            .getVisibleCells()
                            .map((cell: Cell<Account, unknown>) => (
                                <TableCell key={cell.id}>
                                    {flexRender(
                                        cell.column.columnDef.cell,
                                        cell.getContext(),
                                    )}
                                </TableCell>
                            ))}
                    </TableRow>
                </ContextMenuTrigger>
                <ContextMenuContent>
                    <ContextMenuLabel>{__('Actions')}</ContextMenuLabel>
                    <ContextMenuItem onClick={() => setEditOpen(true)}>
                        {__('Edit')}
                    </ContextMenuItem>
                    <ContextMenuItem
                        onClick={() => setDeleteOpen(true)}
                        className="text-red-600"
                    >
                        {__('Delete')}
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <EditAccountDialog
                account={account}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={onSuccess}
            />

            <DeleteAccountDialog
                account={account}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={onSuccess}
            />
        </>
    );
}

interface AccountsPageProps {
    accounts: Account[];
}

export default function Accounts({ accounts }: AccountsPageProps) {
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const breadcrumbs: BreadcrumbItem[] = [
        {
            title: __('Bank accounts'),
            href: accountsIndex.url(),
        },
    ];

    const handleAccountCreated = () => {
        router.reload();
    };

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
                        {__('Name')}

                        <ArrowUpDown className="ml-2 h-4 w-4" />
                    </Button>
                );
            },
            cell: ({ row }) => {
                return (
                    <div className="flex items-center gap-2 pl-3 font-medium">
                        <AccountName
                            account={row.original}
                            length={{ min: 10, max: 20 }}
                        />
                    </div>
                );
            },
        },
        {
            accessorKey: 'bank',
            header: () => __('Bank'),
            cell: ({ row }) => {
                const bank = row.original.bank;
                if (!bank) {
                    return (
                        <div className="min-w-32 text-sm text-muted-foreground">
                            —
                        </div>
                    );
                }
                return (
                    <div className="flex min-w-32 items-center gap-2">
                        <BankLogo
                            src={bank.logo}
                            name={bank.name}
                            className="h-6 w-6 shrink-0"
                            fallback="empty"
                        />
                        <span className="truncate">{bank.name}</span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'type',
            header: () => __('Type'),
            cell: ({ row }) => {
                const isConnected = !!row.original.banking_connection_id;

                return (
                    <div className="flex items-center gap-2">
                        <Badge variant="outline">
                            {formatAccountType(row.getValue('type'))}
                        </Badge>
                        {isConnected && (
                            <Link2
                                className="size-4 text-emerald-600 dark:text-emerald-400"
                                aria-label={__('Connected account')}
                            />
                        )}
                    </div>
                );
            },
        },
        {
            accessorKey: 'currency_code',
            header: () => __('Currency'),
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
            cell: ({ row }) => (
                <AccountActions
                    account={row.original}
                    onSuccess={handleAccountCreated}
                />
            ),
        },
    ];

    const table = useReactTable({
        data: accounts,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        getCoreRowModel: getCoreRowModel(),
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Bank accounts')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Bank accounts')}
                        description={__('Manage your bank accounts')}
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <Input
                                placeholder={__('Filter accounts...')}
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

                            <CreateAccountDialog
                                onSuccess={handleAccountCreated}
                            />
                        </div>

                        <div className="overflow-x-auto rounded-md border">
                            <Table>
                                <TableHeader>
                                    {table
                                        .getHeaderGroups()
                                        .map((headerGroup) => (
                                            <TableRow key={headerGroup.id}>
                                                {headerGroup.headers.map(
                                                    (header) => {
                                                        return (
                                                            <TableHead
                                                                key={header.id}
                                                            >
                                                                {header.isPlaceholder
                                                                    ? null
                                                                    : flexRender(
                                                                          header
                                                                              .column
                                                                              .columnDef
                                                                              .header,
                                                                          header.getContext(),
                                                                      )}
                                                            </TableHead>
                                                        );
                                                    },
                                                )}
                                            </TableRow>
                                        ))}
                                </TableHeader>
                                <TableBody>
                                    {table.getRowModel().rows?.length ? (
                                        table
                                            .getRowModel()
                                            .rows.map((row) => (
                                                <AccountRow
                                                    key={row.id}
                                                    row={row}
                                                    onSuccess={
                                                        handleAccountCreated
                                                    }
                                                />
                                            ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={columns.length}
                                                className="h-24 text-center"
                                            >
                                                {__('No accounts found.')}
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

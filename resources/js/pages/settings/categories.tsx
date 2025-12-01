import { Head } from '@inertiajs/react';
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
import { useLiveQuery } from 'dexie-react-hooks';
import * as Icons from 'lucide-react';
import { ArrowUpDown, MoreHorizontal } from 'lucide-react';
import { useState } from 'react';

import { index as categoriesIndex } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { CreateCategoryDialog } from '@/components/categories/create-category-dialog';
import { DeleteCategoryDialog } from '@/components/categories/delete-category-dialog';
import { EditCategoryDialog } from '@/components/categories/edit-category-dialog';
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
import { db } from '@/lib/dexie-db';
import { type BreadcrumbItem } from '@/types';
import { type Category, getCategoryColorClasses } from '@/types/category';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Categories settings',
        href: categoriesIndex().url,
    },
];

function CategoryActions({ category }: { category: Category }) {
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
                        variant="destructive"
                    >
                        Delete
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditCategoryDialog
                category={category}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />
            <DeleteCategoryDialog
                category={category}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

function CategoryRow({ row }: { row: Row<Category> }) {
    const category = row.original;
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
                            .map((cell: Cell<Category, unknown>) => (
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
                    <ContextMenuLabel>Actions</ContextMenuLabel>
                    <ContextMenuItem onClick={() => setEditOpen(true)}>
                        Edit
                    </ContextMenuItem>
                    <ContextMenuItem
                        onClick={() => setDeleteOpen(true)}
                        variant="destructive"
                    >
                        Delete
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <EditCategoryDialog
                category={category}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />
            <DeleteCategoryDialog
                category={category}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

export default function Categories() {
    const categories = useLiveQuery(() => db.categories.toArray(), []) || [];
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'name', desc: false },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const columns: ColumnDef<Category>[] = [
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
                const iconName = row.original.icon;
                const IconComponent = Icons[iconName as keyof typeof Icons] as
                    | Icons.LucideIcon
                    | undefined;
                const Icon = IconComponent || Icons.Tag;

                return (
                    <div className="flex items-center gap-3 pl-3">
                        <Icon className="h-4 w-4 opacity-80" />
                        <div className="font-medium">
                            {row.getValue('name')}
                        </div>
                    </div>
                );
            },
        },
        {
            accessorKey: 'color',
            header: 'Color',
            cell: ({ row }) => {
                const color = row.getValue('color') as Category['color'];
                if (!color) {
                    return null;
                }
                const colorClasses = getCategoryColorClasses(color);
                if (!colorClasses) {
                    return null;
                }
                return (
                    <Badge
                        className={`${colorClasses.bg} ${colorClasses.text} text-[10px] tracking-widest`}
                    >
                        {color.toLocaleUpperCase()}
                    </Badge>
                );
            },
        },
        {
            accessorKey: 'type',
            header: 'Type',
            cell: ({ row }) => {
                const type = row.getValue('type') as Category['type'];
                const typeConfig = {
                    income: {
                        label: 'Income',
                        className:
                            'bg-green-50 text-green-700 dark:bg-green-700 dark:text-green-100',
                    },
                    expense: {
                        label: 'Expense',
                        className:
                            'bg-red-50 text-red-700 dark:bg-red-700 dark:text-red-100',
                    },
                    transfer: {
                        label: 'Transfer',
                        className:
                            'bg-zinc-50 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100',
                    },
                };
                const config = typeConfig[type];
                return (
                    <Badge
                        className={`${config.className} text-[10px] tracking-widest`}
                    >
                        {config.label.toLocaleUpperCase()}
                    </Badge>
                );
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => <CategoryActions category={row.original} />,
        },
    ];

    const table = useReactTable({
        data: categories,
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
            <Head title="Categories settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Categories settings"
                        description="Manage your transaction categories"
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Input
                                placeholder="Filter categories..."
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
                            <CreateCategoryDialog onSuccess={() => {}} />
                        </div>

                        <div className="overflow-hidden rounded-md border">
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
                                                <CategoryRow
                                                    key={row.id}
                                                    row={row}
                                                />
                                            ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={columns.length}
                                                className="h-24 text-center"
                                            >
                                                No categories found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                category(ies) total.
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

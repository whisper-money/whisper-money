import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
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
import * as Icons from 'lucide-react';
import { ArrowDown, ArrowUp, ArrowUpDown, MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';

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
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import {
    buildCategoryTree,
    type CategoryNode,
    flattenCategoryTree,
} from '@/lib/category-tree';
import { type BreadcrumbItem } from '@/types';
import { type Category, getCategoryColorClasses } from '@/types/category';

type SortField = 'name' | 'color' | 'type';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Categories settings',
        href: categoriesIndex().url,
    },
];

function CategoryActions({
    category,
    categories,
}: {
    category: Category;
    categories: Category[];
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button variant="ghost" className="h-8 w-8 p-0">
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
                        variant="destructive"
                    >
                        {__('Delete')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditCategoryDialog
                category={category}
                categories={categories}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />

            <DeleteCategoryDialog
                category={category}
                categories={categories}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

function CategoryRow({
    row,
    categories,
}: {
    row: Row<Category>;
    categories: Category[];
}) {
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
                                <TableCell
                                    key={cell.id}
                                    className="align-middle"
                                >
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
                        variant="destructive"
                    >
                        {__('Delete')}
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <EditCategoryDialog
                category={category}
                categories={categories}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />

            <DeleteCategoryDialog
                category={category}
                categories={categories}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

export default function Categories() {
    const { categories } = usePage<{ categories: Category[] }>().props;

    const [sortField, setSortField] = useState<SortField>('name');
    const [sortDirection, setSortDirection] = useState<'asc' | 'desc'>('asc');

    const toggleSort = (field: SortField) => {
        if (sortField === field) {
            setSortDirection((current) => (current === 'asc' ? 'desc' : 'asc'));
            return;
        }

        setSortField(field);
        setSortDirection('asc');
    };

    // Sort siblings by the chosen column at every level so subcategories stay
    // grouped under their parent, then flatten to depth-first display order.
    const orderedCategories = useMemo<CategoryNode[]>(() => {
        const compare = (a: Category, b: Category) => {
            const result = String(a[sortField] ?? '').localeCompare(
                String(b[sortField] ?? ''),
            );

            return sortDirection === 'asc' ? result : -result;
        };

        return flattenCategoryTree(buildCategoryTree(categories, compare));
    }, [categories, sortField, sortDirection]);

    const sortHeader = (field: SortField, label: string) => (
        <Button
            variant="ghost"
            className="px-3"
            onClick={() => toggleSort(field)}
        >
            {label}
            {sortField === field ? (
                sortDirection === 'asc' ? (
                    <ArrowUp className="ml-2 h-4 w-4" />
                ) : (
                    <ArrowDown className="ml-2 h-4 w-4" />
                )
            ) : (
                <ArrowUpDown className="ml-2 h-4 w-4 opacity-40" />
            )}
        </Button>
    );

    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const columns: ColumnDef<Category>[] = [
        {
            accessorKey: 'name',
            header: () => sortHeader('name', __('Name')),
            cell: ({ row }) => {
                const iconName = row.original.icon;
                const IconComponent = Icons[iconName as keyof typeof Icons] as
                    | Icons.LucideIcon
                    | undefined;
                const Icon = IconComponent || Icons.Tag;

                const depth = (row.original as CategoryNode).depth ?? 0;

                return (
                    <div
                        className="flex items-center gap-3 pl-3"
                        style={{ paddingLeft: `${0.75 + depth * 1.5}rem` }}
                    >
                        {depth > 0 && (
                            <span
                                aria-hidden
                                className="text-muted-foreground select-none"
                            >
                                └
                            </span>
                        )}
                        <Icon className="h-4 w-4 opacity-80" />
                        <span className="font-medium">
                            {row.getValue('name')}
                        </span>
                    </div>
                );
            },
        },
        {
            accessorKey: 'color',
            header: () => sortHeader('color', __('Color')),
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
                        {__(color).toLocaleUpperCase()}
                    </Badge>
                );
            },
        },
        {
            accessorKey: 'type',
            header: () => sortHeader('type', __('Type')),
            cell: ({ row }) => {
                const type = row.getValue('type') as Category['type'];
                const cashflowDirection = row.original.cashflow_direction;
                const typeConfig = {
                    income: {
                        label: __('Income'),
                        className:
                            'bg-green-50 text-green-700 dark:bg-green-700 dark:text-green-100',
                    },
                    expense: {
                        label: __('Expense'),
                        className:
                            'bg-red-50 text-red-700 dark:bg-red-700 dark:text-red-100',
                    },
                    transfer: {
                        label: __('Transfer'),
                        className:
                            'bg-zinc-50 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-100',
                    },
                    savings: {
                        label: __('Savings'),
                        className:
                            'bg-lime-50 text-lime-700 dark:bg-lime-700 dark:text-lime-100',
                    },
                    investment: {
                        label: __('Investment'),
                        className:
                            'bg-emerald-50 text-emerald-700 dark:bg-emerald-700 dark:text-emerald-100',
                    },
                };
                const cashflowDirectionConfig = {
                    hidden: __('Do not show'),
                    inflow: __('Cash inflow'),
                    outflow: __('Cash outflow'),
                };
                const CashflowVisibilityIcon =
                    cashflowDirection === 'hidden' ? Icons.EyeOff : Icons.Eye;
                const config = typeConfig[type];

                return (
                    <div className="flex items-center gap-2">
                        <Badge
                            className={`${config.className} text-[10px] tracking-widest`}
                        >
                            {config.label.toLocaleUpperCase()}
                        </Badge>

                        {type === 'transfer' && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <button
                                        type="button"
                                        className="text-muted-foreground transition-colors hover:text-foreground"
                                        aria-label={__('Cashflow analytics')}
                                    >
                                        <CashflowVisibilityIcon className="h-3.5 w-3.5" />
                                    </button>
                                </TooltipTrigger>
                                <TooltipContent>
                                    {cashflowDirectionConfig[cashflowDirection]}
                                </TooltipContent>
                            </Tooltip>
                        )}
                    </div>
                );
            },
        },
        {
            accessorKey: 'cashflow_direction',
            header: __('Cashflow analytics'),
            cell: ({ row }) => {
                if (row.original.type !== 'transfer') {
                    return (
                        <span className="text-sm text-muted-foreground">
                            {__('Standard')}
                        </span>
                    );
                }

                const directionConfig = {
                    hidden: __('Do not show'),
                    inflow: __('Cash inflow'),
                    outflow: __('Cash outflow'),
                };

                const direction = row.original.cashflow_direction;

                return (
                    <span className="text-sm">
                        {directionConfig[direction] ?? direction}
                    </span>
                );
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => (
                <CategoryActions
                    category={row.original}
                    categories={categories}
                />
            ),
        },
    ];

    const table = useReactTable({
        data: orderedCategories,
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
            <Head title={__('Categories settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Categories settings')}
                        description={__('Manage your transaction categories')}
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <Input
                                placeholder={__('Filter categories...')}
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

                            <CreateCategoryDialog
                                categories={categories}
                                onSuccess={() => {}}
                            />
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
                                                    categories={categories}
                                                />
                                            ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={columns.length}
                                                className="h-24 text-center align-middle"
                                            >
                                                {__('No categories found.')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                {__('category(ies) total.')}
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

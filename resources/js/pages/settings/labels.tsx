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
import { ArrowUpDown, MoreHorizontal, Tag } from 'lucide-react';
import { useState } from 'react';

import { index as labelsIndex } from '@/actions/App/Http/Controllers/Settings/LabelController';
import HeadingSmall from '@/components/heading-small';
import { CreateLabelDialog } from '@/components/labels/create-label-dialog';
import { DeleteLabelDialog } from '@/components/labels/delete-label-dialog';
import { EditLabelDialog } from '@/components/labels/edit-label-dialog';
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
import { getLabelColorClasses, type Label } from '@/types/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Labels settings',
        href: labelsIndex().url,
    },
];

function LabelActions({ label }: { label: Label }) {
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

            <EditLabelDialog
                label={label}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />
            <DeleteLabelDialog
                label={label}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

function LabelRow({ row }: { row: Row<Label> }) {
    const label = row.original;
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
                            .map((cell: Cell<Label, unknown>) => (
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

            <EditLabelDialog
                label={label}
                open={editOpen}
                onOpenChange={setEditOpen}
                onSuccess={() => {}}
            />
            <DeleteLabelDialog
                label={label}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
                onSuccess={() => {}}
            />
        </>
    );
}

export default function Labels() {
    const labels = useLiveQuery(() => db.labels.toArray(), []) || [];
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'name', desc: false },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const columns: ColumnDef<Label>[] = [
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
                return (
                    <div className="flex items-center gap-3 pl-3">
                        <Tag className="h-4 w-4 opacity-80" />
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
                const color = row.getValue('color') as Label['color'];
                if (!color) {
                    return null;
                }
                const colorClasses = getLabelColorClasses(color);
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
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => <LabelActions label={row.original} />,
        },
    ];

    const table = useReactTable({
        data: labels,
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
            <Head title="Labels settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Labels settings"
                        description="Manage your transaction labels"
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Input
                                placeholder="Filter labels..."
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
                            <CreateLabelDialog onSuccess={() => {}} />
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
                                                <LabelRow
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
                                                No labels found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                label(s) total.
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

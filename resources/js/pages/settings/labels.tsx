import { __ } from '@/utils/i18n';
import { Head, usePage } from '@inertiajs/react';
import {
    ColumnDef,
    ColumnFiltersState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    Row,
    SortingState,
    useReactTable,
    VisibilityState,
} from '@tanstack/react-table';
import { ArrowUpDown, Tag } from 'lucide-react';
import { useState } from 'react';

import { index as labelsIndex } from '@/actions/App/Http/Controllers/Settings/LabelController';
import HeadingSmall from '@/components/heading-small';
import { CreateLabelDialog } from '@/components/labels/create-label-dialog';
import { DeleteLabelDialog } from '@/components/labels/delete-label-dialog';
import { EditLabelDialog } from '@/components/labels/edit-label-dialog';
import {
    type DialogControl,
    RowActionsDropdown,
    RowWithActionsContextMenu,
} from '@/components/shared/row-edit-delete-actions';
import { SettingsTable } from '@/components/shared/settings-table';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { type BreadcrumbItem } from '@/types';
import { getLabelColorClasses, type Label } from '@/types/label';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Labels settings',
        href: labelsIndex().url,
    },
];

function labelDialogs(label: Label) {
    return {
        renderEditDialog: (control: DialogControl) => (
            <EditLabelDialog label={label} onSuccess={() => {}} {...control} />
        ),
        renderDeleteDialog: (control: DialogControl) => (
            <DeleteLabelDialog
                label={label}
                onSuccess={() => {}}
                {...control}
            />
        ),
    };
}

function LabelActions({ label }: { label: Label }) {
    return <RowActionsDropdown {...labelDialogs(label)} />;
}

function LabelRow({ row }: { row: Row<Label> }) {
    return (
        <RowWithActionsContextMenu row={row} {...labelDialogs(row.original)} />
    );
}

export default function Labels() {
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'name', desc: false },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const { labels } = usePage<{ labels: Label[] }>().props;

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
                        {__('Name')}

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
            header: __('Color'),
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
                        {__(color).toLocaleUpperCase()}
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
        getRowId: (row) => row.id,
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
            <Head title={__('Labels settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Labels settings')}
                        description={__('Manage your transaction labels')}
                    />

                    <div className="space-y-4" data-test="labels-table">
                        <div className="flex items-center justify-between gap-4">
                            <Input
                                placeholder={__('Filter labels...')}
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

                        <SettingsTable
                            table={table}
                            emptyMessage={__('No labels found.')}
                            renderRow={(row) => (
                                <LabelRow key={row.id} row={row} />
                            )}
                        />

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                {__('label(s) total.')}
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

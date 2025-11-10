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
import { CreateAutomationRuleDialog } from '@/components/automation-rules/create-automation-rule-dialog';
import { EditAutomationRuleDialog } from '@/components/automation-rules/edit-automation-rule-dialog';
import { DeleteAutomationRuleDialog } from '@/components/automation-rules/delete-automation-rule-dialog';
import {
    type AutomationRule,
    formatRuleActions,
    getRuleActions,
} from '@/types/automation-rule';
import { getCategoryColorClasses } from '@/types/category';
import { type BreadcrumbItem } from '@/types';
import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { useSyncContext } from '@/contexts/sync-context';
import { useEncryptionKey } from '@/contexts/encryption-key-context';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Automation rules settings',
        href: automationRulesIndex().url,
    },
];

function AutomationRuleActions({ rule }: { rule: AutomationRule }) {
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

            <EditAutomationRuleDialog
                rule={rule}
                open={editOpen}
                onOpenChange={setEditOpen}
            />
            <DeleteAutomationRuleDialog
                rule={rule}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />
        </>
    );
}

export default function AutomationRules() {
    const { syncStatus } = useSyncContext();
    const { isKeySet } = useEncryptionKey();
    const [rules, setRules] = useState<AutomationRule[]>([]);
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    useEffect(() => {
        const loadRules = async () => {
            const data = await automationRuleSyncService.getAll();
            setRules(data);
        };

        loadRules();
    }, []);

    useEffect(() => {
        if (syncStatus === 'success') {
            const reloadRules = async () => {
                const data = await automationRuleSyncService.getAll();
                setRules(data);
            };
            reloadRules();
        }
    }, [syncStatus]);

    const columns: ColumnDef<AutomationRule>[] = [
        {
            accessorKey: 'title',
            header: ({ column }) => {
                return (
                    <Button
                        variant="ghost"
                        onClick={() =>
                            column.toggleSorting(column.getIsSorted() === 'asc')
                        }
                    >
                        Title
                        <ArrowUpDown className="ml-2 h-4 w-4" />
                    </Button>
                );
            },
            cell: ({ row }) => {
                return (
                    <div className="pl-3 font-medium">
                        {row.getValue('title')}
                    </div>
                );
            },
        },
        {
            id: 'actions_display',
            header: 'Actions',
            cell: ({ row }) => {
                const rule = row.original;
                const actions = getRuleActions(rule);

                if (actions.type === 'both') {
                    const colorClasses = actions.category
                        ? getCategoryColorClasses(actions.category.color)
                        : null;
                    return (
                        <div className="flex items-center gap-2">
                            {colorClasses && (
                                <Badge
                                    className={`${colorClasses.bg} ${colorClasses.text}`}
                                >
                                    {actions.category?.name}
                                </Badge>
                            )}
                            <span className="text-muted-foreground text-sm">
                                and add note
                            </span>
                        </div>
                    );
                }

                if (actions.type === 'category' && actions.category) {
                    const colorClasses = getCategoryColorClasses(
                        actions.category.color,
                    );
                    return (
                        <Badge
                            className={`${colorClasses.bg} ${colorClasses.text}`}
                        >
                            {actions.category.name}
                        </Badge>
                    );
                }

                return (
                    <span className="text-muted-foreground text-sm">
                        Add note
                    </span>
                );
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => <AutomationRuleActions rule={row.original} />,
        },
    ];

    const table = useReactTable({
        data: rules,
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
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Automation rules settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title="Automation rules settings"
                        description="Manage your transaction automation rules"
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between">
                            <Input
                                placeholder="Filter rules..."
                                value={
                                    (table
                                        .getColumn('title')
                                        ?.getFilterValue() as string) ?? ''
                                }
                                onChange={(event) =>
                                    table
                                        .getColumn('title')
                                        ?.setFilterValue(event.target.value)
                                }
                                className="max-w-sm"
                            />
                            <CreateAutomationRuleDialog disabled={!isKeySet} />
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
                                        table.getRowModel().rows.map((row) => (
                                            <TableRow
                                                key={row.id}
                                                data-state={
                                                    row.getIsSelected() &&
                                                    'selected'
                                                }
                                            >
                                                {row
                                                    .getVisibleCells()
                                                    .map((cell) => (
                                                        <TableCell
                                                            key={cell.id}
                                                        >
                                                            {flexRender(
                                                                cell.column
                                                                    .columnDef
                                                                    .cell,
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
                                                No automation rules found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end space-x-2">
                            <div className="text-muted-foreground flex-1 text-sm">
                                {table.getFilteredRowModel().rows.length} rule(s)
                                total.
                            </div>
                            <div className="space-x-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => table.previousPage()}
                                    disabled={!table.getCanPreviousPage()}
                                >
                                    Previous
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => table.nextPage()}
                                    disabled={!table.getCanNextPage()}
                                >
                                    Next
                                </Button>
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}


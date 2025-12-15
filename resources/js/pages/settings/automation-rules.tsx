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
import { MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';

import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { CreateAutomationRuleDialog } from '@/components/automation-rules/create-automation-rule-dialog';
import { DeleteAutomationRuleDialog } from '@/components/automation-rules/delete-automation-rule-dialog';
import { EditAutomationRuleDialog } from '@/components/automation-rules/edit-automation-rule-dialog';
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
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { db } from '@/lib/dexie-db';
import { type BreadcrumbItem } from '@/types';
import { type AutomationRule, getRuleActions } from '@/types/automation-rule';
import { getCategoryColorClasses } from '@/types/category';

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

function AutomationRuleRow({ row }: { row: Row<AutomationRule> }) {
    const rule = row.original;
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
                            .map((cell: Cell<AutomationRule, unknown>) => (
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
    const { isKeySet } = useEncryptionKey();
    const rawRules =
        useLiveQuery(() => db.automation_rules.toArray(), []) || [];
    const rules = useMemo(
        () =>
            rawRules.map((rule) => ({
                ...rule,
                rules_json:
                    typeof rule.rules_json === 'string'
                        ? JSON.parse(rule.rules_json)
                        : rule.rules_json,
            })),
        [rawRules],
    );
    const [sorting, setSorting] = useState<SortingState>([
        {
            id: 'priority',
            desc: false,
        },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const columns: ColumnDef<AutomationRule>[] = [
        {
            accessorKey: 'priority',
            header: 'Priority',
            cell: ({ row }) => {
                return (
                    <div className="font-medium">
                        {row.getValue('priority')}
                    </div>
                );
            },
        },
        {
            accessorKey: 'title',
            header: 'Title',
            cell: ({ row }) => {
                return (
                    <div className="font-medium">{row.getValue('title')}</div>
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
                    const IconComponent = actions.category
                        ? (Icons[
                            actions.category.icon as keyof typeof Icons
                        ] as Icons.LucideIcon)
                        : null;
                    return (
                        <div className="flex items-center gap-2">
                            {colorClasses && (
                                <Badge
                                    className={`${colorClasses.bg} ${colorClasses.text} flex items-center gap-2`}
                                >
                                    {IconComponent && (
                                        <IconComponent className="h-3 w-3 opacity-80" />
                                    )}
                                    {actions.category?.name}
                                </Badge>
                            )}
                            <span className="text-sm text-muted-foreground">
                                and add note
                            </span>
                        </div>
                    );
                }

                if (actions.type === 'category' && actions.category) {
                    const colorClasses = getCategoryColorClasses(
                        actions.category.color,
                    );
                    const IconComponent = Icons[
                        actions.category.icon as keyof typeof Icons
                    ] as Icons.LucideIcon;
                    return (
                        <Badge
                            className={`${colorClasses.bg} ${colorClasses.text} flex items-center gap-2`}
                        >
                            <IconComponent className="h-3 w-3 opacity-80" />
                            {actions.category.name}
                        </Badge>
                    );
                }

                return (
                    <span className="text-sm text-muted-foreground">
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
                        <div className="flex gap-4 items-center justify-between">
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
                                        table
                                            .getRowModel()
                                            .rows.map((row) => (
                                                <AutomationRuleRow
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
                                                No automation rules found.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                rule(s) total.
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

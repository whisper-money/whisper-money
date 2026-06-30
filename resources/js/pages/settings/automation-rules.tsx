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
import { MoreHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';

import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { ApplyAutomationRuleDialog } from '@/components/automation-rules/apply-automation-rule-dialog';
import { AutomationRuleActionBadges } from '@/components/automation-rules/automation-rule-action-badges';
import { AutomationRuleTitle } from '@/components/automation-rules/automation-rule-title';
import { CreateAutomationRuleDialog } from '@/components/automation-rules/create-automation-rule-dialog';
import { DeleteAutomationRuleDialog } from '@/components/automation-rules/delete-automation-rule-dialog';
import { EditAutomationRuleDialog } from '@/components/automation-rules/edit-automation-rule-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import HeadingSmall from '@/components/heading-small';
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
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Automation rules settings',
        href: automationRulesIndex().url,
    },
];

function AutomationRuleActions({
    rule,
    categories,
    labels,
}: {
    rule: AutomationRule;
    categories: Category[];
    labels: Label[];
}) {
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);

    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="ghost"
                        className="h-8 w-8 p-0"
                        aria-label={__('Actions')}
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
                    <DropdownMenuItem onClick={() => setApplyOpen(true)}>
                        {__('Apply to existing transactions')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onClick={() => setDeleteOpen(true)}
                        variant="destructive"
                    >
                        {__('Delete')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <EditAutomationRuleDialog
                rule={rule}
                categories={categories}
                labels={labels}
                open={editOpen}
                onOpenChange={setEditOpen}
            />
            <ApplyAutomationRuleDialog
                rule={rule}
                open={applyOpen}
                onOpenChange={setApplyOpen}
            />
            <DeleteAutomationRuleDialog
                rule={rule}
                open={deleteOpen}
                onOpenChange={setDeleteOpen}
            />
        </>
    );
}

function AutomationRuleRow({
    row,
    categories,
    labels,
}: {
    row: Row<AutomationRule>;
    categories: Category[];
    labels: Label[];
}) {
    const rule = row.original;
    const [editOpen, setEditOpen] = useState(false);
    const [deleteOpen, setDeleteOpen] = useState(false);
    const [applyOpen, setApplyOpen] = useState(false);
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
                    <ContextMenuItem onClick={() => setApplyOpen(true)}>
                        {__('Apply to existing transactions')}
                    </ContextMenuItem>
                    <ContextMenuItem
                        onClick={() => setDeleteOpen(true)}
                        variant="destructive"
                    >
                        {__('Delete')}
                    </ContextMenuItem>
                </ContextMenuContent>
            </ContextMenu>

            <EditAutomationRuleDialog
                rule={rule}
                categories={categories}
                labels={labels}
                open={editOpen}
                onOpenChange={setEditOpen}
            />
            <ApplyAutomationRuleDialog
                rule={rule}
                open={applyOpen}
                onOpenChange={setApplyOpen}
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
    const { automationRules: rawRules } = usePage<{
        automationRules: AutomationRule[];
    }>().props;

    const categories = usePage().props.categories as Category[];
    const labels = usePage().props.labels as Label[];
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
    const [sorting, setSorting] = useState<SortingState>([]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        {},
    );

    const columns: ColumnDef<AutomationRule>[] = [
        {
            accessorKey: 'title',
            header: __('Title'),
            cell: ({ row }) => {
                return <AutomationRuleTitle rule={row.original} />;
            },
        },
        {
            id: 'actions_display',
            header: __('Actions'),
            cell: ({ row }) => {
                return <AutomationRuleActionBadges rule={row.original} />;
            },
        },
        {
            id: 'actions',
            enableHiding: false,
            cell: ({ row }) => (
                <AutomationRuleActions
                    rule={row.original}
                    categories={categories}
                    labels={labels}
                />
            ),
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
            <Head title={__('Automation rules settings')} />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall
                        title={__('Automation rules settings')}
                        description={__(
                            'Manage rules that categorize transactions and add labels automatically',
                        )}
                    />

                    <div className="space-y-4">
                        <div className="flex items-center justify-between gap-4">
                            <Input
                                placeholder={__('Filter rules...')}
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
                            <CreateAutomationRuleDialog
                                categories={categories}
                                labels={labels}
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
                                                <AutomationRuleRow
                                                    key={row.id}
                                                    row={row}
                                                    categories={categories}
                                                    labels={labels}
                                                />
                                            ))
                                    ) : (
                                        <TableRow>
                                            <TableCell
                                                colSpan={columns.length}
                                                className="h-24 text-center"
                                            >
                                                {__(
                                                    'No automation rules found.',
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="flex items-center justify-end">
                            <div className="text-sm text-muted-foreground">
                                {table.getFilteredRowModel().rows.length}{' '}
                                {__('rule(s) total.')}
                            </div>
                        </div>
                    </div>
                </div>
            </SettingsLayout>
            <PostSaveApplyRulePrompt />
        </AppLayout>
    );
}

import { useLocale } from '@/hooks/use-locale';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Cell,
    Row,
    SortingState,
    VisibilityState,
    flexRender,
    getCoreRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import axios from 'axios';
import { format, getYear, parseISO } from 'date-fns';
import {
    createElement,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';

import {
    bulk as reEvaluateBulk,
    single as reEvaluateSingle,
    status as reEvaluateStatus,
} from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import {
    AutomateCategorizationDialog,
    type AutomateCategorizationCandidate,
} from '@/components/automation-rules/automate-categorization-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import HeadingSmall from '@/components/heading-small';
import { BulkActionsBar } from '@/components/transactions/bulk-actions-bar';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { TransactionActionsMenu } from '@/components/transactions/transaction-actions-menu';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
import { TransactionFilters as TransactionFiltersComponent } from '@/components/transactions/transaction-filters';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    ContextMenu,
    ContextMenuContent,
    ContextMenuItem,
    ContextMenuLabel,
    ContextMenuTrigger,
} from '@/components/ui/context-menu';
import { DataTable } from '@/components/ui/data-table';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { DataTableViewOptions } from '@/components/ui/data-table-view-options';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { TableCell, TableRow } from '@/components/ui/table';
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import {
    isCursorPaginatedResponse,
    type CursorPaginatedResponse,
} from '@/lib/cursor-pagination';
import { consoleDebug } from '@/lib/debug';
import { captureEvent } from '@/lib/posthog';
import { getBulkDeleteConfirmationText } from '@/lib/transaction-delete-confirmation';
import { mergeReEvaluatedTransaction } from '@/lib/transaction-re-evaluation';
import { cn } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type BreadcrumbItem } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
    type ServerTransaction,
} from '@/types/transaction';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transactions',
        href: transactionsIndex.url(),
    },
];

interface AppliedFilters {
    date_from: string | null;
    date_to: string | null;
    amount_min: number | null;
    amount_max: number | null;
    category_ids: string[];
    account_ids: string[];
    label_ids: string[];
    creditor_name: string;
    debtor_name: string;
    search: string;
    sort: string;
}

interface Props {
    transactions: CursorPaginatedResponse<ServerTransaction>;
    appliedFilters: AppliedFilters;
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
    automationRules: AutomationRule[];
}

const COLUMN_VISIBILITY_KEY = 'transactions-column-visibility';

function serverToClientFilters(applied: AppliedFilters): Filters {
    return {
        dateFrom: applied.date_from
            ? new Date(applied.date_from + 'T00:00:00')
            : null,
        dateTo: applied.date_to
            ? new Date(applied.date_to + 'T00:00:00')
            : null,
        amountMin: applied.amount_min,
        amountMax: applied.amount_max,
        categoryIds: applied.category_ids,
        accountIds: applied.account_ids,
        labelIds: applied.label_ids,
        creditorName: applied.creditor_name,
        debtorName: applied.debtor_name,
        searchText: applied.search,
    };
}

function clientFiltersToQueryParams(
    filters: Filters,
    sort: string,
): Record<string, string> {
    const params: Record<string, string> = {};

    if (filters.dateFrom) {
        params.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
    }
    if (filters.dateTo) {
        params.date_to = format(filters.dateTo, 'yyyy-MM-dd');
    }
    if (filters.amountMin !== null) {
        params.amount_min = filters.amountMin.toString();
    }
    if (filters.amountMax !== null) {
        params.amount_max = filters.amountMax.toString();
    }
    if (filters.categoryIds.length > 0) {
        params.category_ids = filters.categoryIds.join(',');
    }
    if (filters.accountIds.length > 0) {
        params.account_ids = filters.accountIds.join(',');
    }
    if (filters.labelIds.length > 0) {
        params.label_ids = filters.labelIds.join(',');
    }
    if (filters.creditorName) {
        params.creditor_name = filters.creditorName;
    }
    if (filters.debtorName) {
        params.debtor_name = filters.debtorName;
    }
    if (filters.searchText) {
        params.search = filters.searchText;
    }
    if (sort !== '-transaction_date') {
        params.sort = sort;
    }

    return params;
}

function clientFiltersToBackendFilters(
    filters: Filters,
): Record<string, unknown> {
    const result: Record<string, unknown> = {};

    if (filters.dateFrom) {
        result.date_from = format(filters.dateFrom, 'yyyy-MM-dd');
    }
    if (filters.dateTo) {
        result.date_to = format(filters.dateTo, 'yyyy-MM-dd');
    }
    if (filters.amountMin !== null && filters.amountMin !== undefined) {
        result.amount_min = filters.amountMin;
    }
    if (filters.amountMax !== null && filters.amountMax !== undefined) {
        result.amount_max = filters.amountMax;
    }
    if (filters.categoryIds.length > 0) {
        result.category_ids = filters.categoryIds;
    }
    if (filters.accountIds.length > 0) {
        result.account_ids = filters.accountIds;
    }
    if (filters.labelIds.length > 0) {
        result.label_ids = filters.labelIds;
    }
    if (filters.creditorName) {
        result.creditor_name = filters.creditorName;
    }
    if (filters.debtorName) {
        result.debtor_name = filters.debtorName;
    }
    if (filters.searchText) {
        result.search = filters.searchText;
    }

    return result;
}

function toDecryptedTransaction(tx: ServerTransaction): DecryptedTransaction {
    return {
        ...tx,
        transaction_date: String(tx.transaction_date).slice(0, 10),
        decryptedDescription: tx.description_iv ? '' : tx.description,
        decryptedNotes: tx.notes_iv ? null : tx.notes || null,
        bank: tx.account?.bank || undefined,
        labels: tx.labels || [],
        label_ids: tx.labels?.map((l) => l.id) || [],
    };
}

interface TransactionRowProps {
    row: Row<DecryptedTransaction>;
    virtualRow: VirtualItem;
    rowVirtualizer: Virtualizer<HTMLDivElement, Element>;
    onEdit: (transaction: DecryptedTransaction) => void;
    onReEvaluateRules: (transaction: DecryptedTransaction) => void;
    onDelete: (transaction: DecryptedTransaction) => void;
}

function TransactionRowComponent({
    row,
    virtualRow,
    rowVirtualizer,
    onEdit,
    onReEvaluateRules,
    onDelete,
}: TransactionRowProps) {
    const transaction = row.original;
    const [contextMenuOpen, setContextMenuOpen] = useState(false);

    const handleRowClick = (event: React.MouseEvent<HTMLTableRowElement>) => {
        const target = event.target as HTMLElement;
        const isInteractive = target.closest(
            'button, [role="checkbox"], [role="combobox"], [role="menuitem"], [role="option"], [cmdk-item], a, input, select, textarea, [data-radix-popper-content-wrapper]',
        );
        if (isInteractive) {
            return;
        }
        onEdit(transaction);
    };

    return (
        <ContextMenu key={row.id} onOpenChange={setContextMenuOpen}>
            <ContextMenuTrigger asChild>
                <TableRow
                    ref={rowVirtualizer.measureElement}
                    data-state={
                        (row.getIsSelected() || contextMenuOpen) && 'selected'
                    }
                    data-index={virtualRow.index}
                    className="cursor-pointer"
                    onClick={handleRowClick}
                >
                    {row
                        .getVisibleCells()
                        .filter((cell: Cell<DecryptedTransaction, unknown>) => {
                            const meta = cell.column.columnDef.meta as
                                | {
                                      isVirtual?: boolean;
                                      cellClassName?: string;
                                      cellStyle?: React.CSSProperties;
                                  }
                                | undefined;
                            return !meta?.isVirtual;
                        })
                        .map((cell: Cell<DecryptedTransaction, unknown>) => {
                            const meta = cell.column.columnDef.meta as
                                | {
                                      cellClassName?: string;
                                      cellStyle?: React.CSSProperties;
                                  }
                                | undefined;
                            return (
                                <TableCell
                                    key={cell.id}
                                    className={cn(
                                        meta?.cellClassName,
                                        'pt-2.5 pb-2',
                                    )}
                                    style={meta?.cellStyle}
                                >
                                    {flexRender(
                                        cell.column.columnDef.cell,
                                        cell.getContext(),
                                    )}
                                </TableCell>
                            );
                        })}
                </TableRow>
            </ContextMenuTrigger>
            <ContextMenuContent>
                <ContextMenuLabel>{__('Actions')}</ContextMenuLabel>
                <ContextMenuItem onClick={() => onEdit(transaction)}>
                    {__('Edit')}
                </ContextMenuItem>
                <ContextMenuItem onClick={() => onReEvaluateRules(transaction)}>
                    {__('Re-evaluate rules')}
                </ContextMenuItem>
                <ContextMenuItem
                    onClick={() => onDelete(transaction)}
                    variant="destructive"
                >
                    {__('Delete')}
                </ContextMenuItem>
            </ContextMenuContent>
        </ContextMenu>
    );
}

function getInitialColumnVisibility(): VisibilityState {
    const defaultVisibility = {
        transaction_date: true,
        account: true,
        creditor_name: false,
        debtor_name: false,
        labels: true,
        notes: false,
    };
    if (typeof window === 'undefined') {
        return defaultVisibility;
    }

    try {
        const stored = localStorage.getItem(COLUMN_VISIBILITY_KEY);
        if (stored) {
            return { ...defaultVisibility, ...JSON.parse(stored) };
        }
    } catch (error) {
        console.error(
            'Failed to load column visibility from localStorage:',
            error,
        );
    }
    return defaultVisibility;
}

function DateHeader({ date, colSpan }: { date: string; colSpan: number }) {
    const parsedDate = parseISO(date);
    const currentYear = getYear(new Date());
    const transactionYear = getYear(parsedDate);
    const formatString =
        transactionYear === currentYear ? 'MMM d' : 'MMM d, yy';

    return (
        <tr className="hidden">
            <td
                colSpan={colSpan}
                className="px-4 pt-2 text-sm text-muted-foreground"
            >
                {format(parsedDate, formatString)}
            </td>
        </tr>
    );
}

export default function Transactions({
    transactions: serverTransactions,
    appliedFilters,
    categories,
    accounts,
    banks,
    labels: initialLabels,
    automationRules,
}: Props) {
    const locale = useLocale();
    const [labels, setLabels] = useState<Label[]>(() => initialLabels);

    useEffect(() => {
        setLabels(initialLabels);
    }, [initialLabels]);

    const handleLabelCreated = useCallback((label: Label) => {
        setLabels((previousLabels) => {
            const nextLabels = previousLabels.filter(
                (existingLabel) => existingLabel.id !== label.id,
            );

            return [...nextLabels, label].sort((a, b) =>
                a.name.localeCompare(b.name),
            );
        });
    }, []);

    // Convert server transactions to DecryptedTransaction for column compatibility
    const [allTransactions, setAllTransactions] = useState<
        DecryptedTransaction[]
    >(() => serverTransactions.data.map(toDecryptedTransaction));
    const [nextCursor, setNextCursor] = useState<string | null>(
        serverTransactions.next_cursor,
    );

    // Reset is handled explicitly via onSuccess in navigateWithFilters,
    // and append is handled via onSuccess in handleLoadMore.
    // No useEffect watching serverTransactions — avoids race conditions.

    // Filter state from server-applied filters
    const [filters, setFilters] = useState<Filters>(() =>
        serverToClientFilters(appliedFilters),
    );
    const [sortParam, setSortParam] = useState(
        appliedFilters.sort || '-transaction_date',
    );

    // Sync filter state when appliedFilters prop changes
    useEffect(() => {
        setFilters(serverToClientFilters(appliedFilters));
        setSortParam(appliedFilters.sort || '-transaction_date');
    }, [appliedFilters]);

    // Derive sorting state for TanStack Table from sort param
    const sorting = useMemo<SortingState>(() => {
        const desc = sortParam.startsWith('-');
        const column = sortParam.replace(/^-/, '');
        return [{ id: column, desc }];
    }, [sortParam]);

    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        getInitialColumnVisibility(),
    );
    const [rowSelection, setRowSelection] = useState({});
    const [editTransaction, setEditTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [deleteTransaction, setDeleteTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [isBulkDeleteMode, setIsBulkDeleteMode] = useState(false);
    const [updateBalanceOnDelete, setUpdateBalanceOnDelete] = useState(true);
    const [bulkDeleteConfirmation, setBulkDeleteConfirmation] = useState('');
    const [isDeleting, setIsDeleting] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [isBulkUpdating, setIsBulkUpdating] = useState(false);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const [automateDialogOpen, setAutomateDialogOpen] = useState(false);
    const [automateCandidate, setAutomateCandidate] =
        useState<AutomateCategorizationCandidate | null>(null);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [isSelectingAll, setIsSelectingAll] = useState(false);
    const [isNavigating, setIsNavigating] = useState(false);

    // Debounce timer ref for search
    const searchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    // Navigate to server with new filters/sort
    const navigateWithFilters = useCallback(
        (newFilters: Filters, newSort: string, debounceMs = 0) => {
            if (searchTimerRef.current) {
                clearTimeout(searchTimerRef.current);
            }

            const doNavigate = () => {
                const params = clientFiltersToQueryParams(newFilters, newSort);
                setIsNavigating(true);
                setRowSelection({});
                setIsSelectingAll(false);
                router.visit(transactionsIndex({ query: params }).url, {
                    preserveScroll: true,
                    preserveState: true,
                    onSuccess: (page) => {
                        const txns = (page.props as { transactions?: unknown })
                            .transactions;

                        if (
                            !isCursorPaginatedResponse<ServerTransaction>(txns)
                        ) {
                            return;
                        }

                        setAllTransactions(
                            txns.data.map(toDecryptedTransaction),
                        );
                        setNextCursor(txns.next_cursor);
                    },
                    onFinish: () => setIsNavigating(false),
                });
            };

            if (debounceMs > 0) {
                searchTimerRef.current = setTimeout(doNavigate, debounceMs);
            } else {
                doNavigate();
            }
        },
        [],
    );

    // Handle filter changes from the filter component
    const handleFiltersChange = useCallback(
        (newFilters: Filters) => {
            setFilters(newFilters);

            // Debounce search, immediate for other filters
            const isSearchChange = newFilters.searchText !== filters.searchText;
            const onlySearchChanged =
                isSearchChange &&
                newFilters.dateFrom === filters.dateFrom &&
                newFilters.dateTo === filters.dateTo &&
                newFilters.amountMin === filters.amountMin &&
                newFilters.amountMax === filters.amountMax &&
                JSON.stringify(newFilters.categoryIds) ===
                    JSON.stringify(filters.categoryIds) &&
                JSON.stringify(newFilters.accountIds) ===
                    JSON.stringify(filters.accountIds) &&
                JSON.stringify(newFilters.labelIds) ===
                    JSON.stringify(filters.labelIds) &&
                newFilters.creditorName === filters.creditorName &&
                newFilters.debtorName === filters.debtorName;

            navigateWithFilters(
                newFilters,
                sortParam,
                onlySearchChanged ? 300 : 0,
            );
        },
        [filters, sortParam, navigateWithFilters],
    );

    // Handle sort changes from table column headers
    const handleSortingChange = useCallback(
        (updater: SortingState | ((prev: SortingState) => SortingState)) => {
            const newSorting =
                typeof updater === 'function' ? updater(sorting) : updater;
            if (newSorting.length === 0) {
                return;
            }
            const { id, desc } = newSorting[0];
            const newSort = desc ? `-${id}` : id;
            setSortParam(newSort);
            navigateWithFilters(filters, newSort);
        },
        [sorting, filters, navigateWithFilters],
    );

    // Refresh the transaction list from the server (resets accumulated pages)
    const refreshTransactions = useCallback(() => {
        router.reload({
            only: ['transactions'],
            onSuccess: (page) => {
                const txns = (page.props as { transactions?: unknown })
                    .transactions;

                if (!isCursorPaginatedResponse<ServerTransaction>(txns)) {
                    return;
                }

                setAllTransactions(txns.data.map(toDecryptedTransaction));
                setNextCursor(txns.next_cursor);
            },
        });
    }, []);

    // Load More with cursor pagination (fetch directly to avoid cursor in URL)
    const { component, version } = usePage();
    const handleLoadMore = useCallback(async () => {
        if (!nextCursor || isLoadingMore) {
            return;
        }

        setIsLoadingMore(true);
        try {
            const params = clientFiltersToQueryParams(filters, sortParam);
            params.cursor = nextCursor;
            const url = transactionsIndex({ query: params }).url;

            const response = await fetch(url, {
                headers: {
                    'X-Inertia': 'true',
                    'X-Inertia-Version': version ?? '',
                    'X-Inertia-Partial-Data': 'transactions',
                    'X-Inertia-Partial-Component': component,
                    Accept: 'text/html, application/xhtml+xml',
                },
            });

            const json = await response.json();
            const next = json.props.transactions;

            if (!isCursorPaginatedResponse<ServerTransaction>(next)) {
                return;
            }

            setAllTransactions((prev) => [
                ...prev,
                ...next.data.map(toDecryptedTransaction),
            ]);
            setNextCursor(next.next_cursor);
        } catch (error) {
            console.error('Failed to load more transactions:', error);
        } finally {
            setIsLoadingMore(false);
        }
    }, [nextCursor, isLoadingMore, filters, sortParam, component, version]);

    // Auto-load more when the sentinel becomes visible
    const loadMoreRef = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        const el = loadMoreRef.current;
        if (!el || !nextCursor) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0]?.isIntersecting) {
                    handleLoadMore();
                }
            },
            { rootMargin: '200px' },
        );

        observer.observe(el);
        return () => observer.disconnect();
    }, [nextCursor, handleLoadMore]);

    // Persist column visibility
    useEffect(() => {
        try {
            localStorage.setItem(
                COLUMN_VISIBILITY_KEY,
                JSON.stringify(columnVisibility),
            );
        } catch (error) {
            console.error(
                'Failed to save column visibility to localStorage:',
                error,
            );
        }
    }, [columnVisibility]);

    const updateTransaction = useCallback(
        (updatedTransaction: DecryptedTransaction) => {
            setAllTransactions((previous) =>
                previous.map((transaction) => {
                    if (transaction.id !== updatedTransaction.id) {
                        return transaction;
                    }

                    return {
                        ...transaction,
                        ...updatedTransaction,
                        account:
                            updatedTransaction.account === undefined
                                ? transaction.account
                                : updatedTransaction.account,
                        bank:
                            updatedTransaction.bank === undefined
                                ? transaction.bank
                                : updatedTransaction.bank,
                        category:
                            updatedTransaction.category === undefined
                                ? (transaction.category ?? null)
                                : updatedTransaction.category,
                    };
                }),
            );
        },
        [],
    );

    const handleReEvaluateRules = useCallback(
        async (transaction: DecryptedTransaction) => {
            consoleDebug('=== Re-evaluating rules for single transaction ===');

            setIsReEvaluating(true);
            try {
                const response = await axios.post<{
                    data: DecryptedTransaction;
                }>(reEvaluateSingle({ transaction: transaction.id }).url);

                const updated = response.data.data;

                updateTransaction(
                    mergeReEvaluatedTransaction(transaction, updated),
                );
            } catch (error) {
                console.error('Failed to re-evaluate rules:', error);
            } finally {
                setIsReEvaluating(false);
            }
        },
        [updateTransaction],
    );

    async function handleBulkReEvaluateRules() {
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');

        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsReEvaluating(true);
        const toastId = toast.loading(`Re-evaluating 0 of ... transactions...`);

        try {
            const payload = isSelectingAll
                ? { filters: clientFiltersToBackendFilters(filters) }
                : { transaction_ids: selectedIds };

            const bulkResponse = await axios.post<{ job_id: string }>(
                reEvaluateBulk().url,
                payload,
            );

            const jobId = bulkResponse.data.job_id;

            await new Promise<void>((resolve, reject) => {
                const poll = async () => {
                    try {
                        const statusResponse = await axios.get<{
                            status: string;
                            processed: number;
                            total: number;
                            updated: number;
                        }>(reEvaluateStatus({ jobId }).url);

                        const { status, processed, total, updated } =
                            statusResponse.data;

                        toast.loading(
                            `Re-evaluating ${processed} of ${total} transactions...`,
                            { id: toastId },
                        );

                        if (status === 'done') {
                            toast.dismiss(toastId);
                            toast.success(() => (
                                <div>
                                    {`Re-evaluation complete!`}
                                    <br />
                                    {`${updated} transaction(s) updated.`}
                                </div>
                            ));
                            resolve();
                        } else if (status === 'failed') {
                            reject(new Error('Job failed'));
                        } else {
                            setTimeout(poll, 1000);
                        }
                    } catch (error) {
                        reject(error);
                    }
                };

                poll();
            });

            setRowSelection({});
            setIsSelectingAll(false);
            refreshTransactions();
        } catch (error) {
            console.error('Failed to re-evaluate rules:', error);
            toast.error(__('Failed to re-evaluate rules. Please try again.'), {
                id: toastId,
            });
        } finally {
            setIsReEvaluating(false);
        }
    }

    const showAutomatizeToast = useCallback(
        (
            transaction: DecryptedTransaction,
            category: Category,
            source: 'transaction_table' | 'edit_transaction_modal',
        ) => {
            const nextAutomateCandidate = { transaction, category };

            setAutomateCandidate(nextAutomateCandidate);
            toast.success(__('Transaction categorized'), {
                closeButton: true,
                duration: 12000,
                action: {
                    label: createElement(
                        'span',
                        {
                            title: __(
                                'Automatize the categorization of future transactions like this one',
                            ),
                        },
                        __('Automatize'),
                    ),
                    onClick: () => {
                        captureEvent(
                            'automation_rule_toast_automatize_clicked',
                            { source },
                        );
                        setAutomateCandidate(nextAutomateCandidate);
                        setAutomateDialogOpen(true);
                    },
                },
            });
        },
        [],
    );

    const columns = useMemo(
        () =>
            createTransactionColumns({
                categories,
                accounts,
                banks,
                labels,
                locale,
                onEdit: setEditTransaction,
                onDelete: setDeleteTransaction,
                onUpdate: updateTransaction,
                onCategorized: showAutomatizeToast,
                onReEvaluateRules: handleReEvaluateRules,
            }),
        [
            accounts,
            banks,
            categories,
            labels,
            locale,
            updateTransaction,
            showAutomatizeToast,
            handleReEvaluateRules,
        ],
    );

    const table = useReactTable({
        data: allTransactions,
        columns,
        manualSorting: true,
        onSortingChange: handleSortingChange,
        getRowId: (row) => row.id.toString(),
        getCoreRowModel: getCoreRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        onRowSelectionChange: setRowSelection,
        enableRowSelection: true,
        state: {
            sorting,
            columnVisibility,
            rowSelection,
        },
    });

    async function handleDelete() {
        if (!deleteTransaction) {
            return;
        }

        setIsDeleting(true);
        try {
            await transactionSyncService.delete(deleteTransaction.id, {
                updateBalance:
                    updateBalanceOnDelete &&
                    manualAccountIds.has(deleteTransaction.account_id),
            });
            setAllTransactions((previous) =>
                previous.filter(
                    (transaction) => transaction.id !== deleteTransaction.id,
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transaction:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    async function handleBulkCategoryChange(categoryId: number | null) {
        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            if (isSelectingAll) {
                const toastId = toast.loading(__('Updating transactions...'));
                const response = await axios.patch<{ count: number }>(
                    '/transactions/bulk',
                    {
                        filters: clientFiltersToBackendFilters(filters),
                        category_id: categoryId,
                    },
                );
                toast.dismiss(toastId);
                toast.success(
                    __(`Updated :count transactions`, {
                        count: response.data.count,
                    }),
                );
                setRowSelection({});
                setIsSelectingAll(false);
                refreshTransactions();
            } else {
                const categoriesMap = new Map(
                    categories.map((category) => [category.id, category]),
                );
                const selectedCategory = categoryId
                    ? categoriesMap.get(categoryId) || null
                    : null;

                await transactionSyncService.updateMany(selectedIds, {
                    category_id: categoryId,
                });

                setAllTransactions((previous) =>
                    previous.map((transaction) => {
                        if (selectedIds.includes(transaction.id.toString())) {
                            return {
                                ...transaction,
                                category_id: categoryId,
                                category: selectedCategory,
                            };
                        }
                        return transaction;
                    }),
                );

                toast.success(
                    __(`Updated :count transactions`, {
                        count: selectedIds.length,
                    }),
                );

                setRowSelection({});
            }
        } catch (error) {
            console.error('Failed to update transactions:', error);
            toast.error(__('Failed to update transactions'));
        } finally {
            setIsBulkUpdating(false);
        }
    }

    const selectedIds = useMemo(
        () => Object.keys(rowSelection),
        [rowSelection],
    );

    const selectedCount = useMemo(() => selectedIds.length, [selectedIds]);

    const manualAccountIds = useMemo(
        () =>
            new Set(
                accounts
                    .filter((account) => account.banking_connection_id === null)
                    .map((account) => account.id),
            ),
        [accounts],
    );

    const bulkDeleteTouchesManualAccount = useMemo(
        () =>
            isSelectingAll
                ? manualAccountIds.size > 0
                : allTransactions.some(
                      (transaction) =>
                          selectedIds.includes(transaction.id) &&
                          manualAccountIds.has(transaction.account_id),
                  ),
        [isSelectingAll, manualAccountIds, allTransactions, selectedIds],
    );

    const bulkDeleteConfirmationText = useMemo(
        () => getBulkDeleteConfirmationText(selectedCount),
        [selectedCount],
    );

    function handleBulkDeleteClick() {
        if (selectedIds.length === 0) {
            return;
        }

        const firstSelectedTransaction = allTransactions.find(
            (t) => t.id.toString() === selectedIds[0],
        );

        if (firstSelectedTransaction) {
            setIsBulkDeleteMode(true);
            setDeleteTransaction(firstSelectedTransaction);
        }
    }

    async function handleBulkDelete() {
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkDeleting(true);
        try {
            await transactionSyncService.deleteMany(selectedIds, {
                updateBalance: updateBalanceOnDelete,
            });
            setAllTransactions((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setBulkDeleteConfirmation('');
            setRowSelection({});
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        } finally {
            setIsBulkDeleting(false);
        }
    }

    async function handleBulkLabelsChange(labelIds: string[]) {
        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            if (isSelectingAll) {
                const toastId = toast.loading(__('Updating transactions...'));
                const response = await axios.patch<{ count: number }>(
                    '/transactions/bulk',
                    {
                        filters: clientFiltersToBackendFilters(filters),
                        label_ids: labelIds,
                    },
                );
                toast.dismiss(toastId);
                toast.success(
                    __(`Updated :count transactions`, {
                        count: response.data.count,
                    }),
                );
                setRowSelection({});
                setIsSelectingAll(false);
                refreshTransactions();
            } else {
                const selectedLabels = labels.filter((l) =>
                    labelIds.includes(l.id),
                );

                await transactionSyncService.updateMany(selectedIds, {
                    label_ids: labelIds,
                });

                setAllTransactions((previous) =>
                    previous.map((transaction) => {
                        if (selectedIds.includes(transaction.id.toString())) {
                            if (labelIds.length === 0) {
                                return {
                                    ...transaction,
                                    labels: [],
                                };
                            }

                            const existingLabels = transaction.labels || [];
                            const mergedLabels = [
                                ...existingLabels,
                                ...selectedLabels.filter(
                                    (l) =>
                                        !existingLabels.some(
                                            (el) => el.id === l.id,
                                        ),
                                ),
                            ];

                            return {
                                ...transaction,
                                labels: mergedLabels,
                            };
                        }
                        return transaction;
                    }),
                );

                toast.success(
                    __(`Updated :count transactions`, {
                        count: selectedIds.length,
                    }),
                );

                setRowSelection({});
            }
        } catch (error) {
            console.error('Failed to update transactions with labels:', error);
            toast.error(__('Failed to update transactions with labels'));
        } finally {
            setIsBulkUpdating(false);
        }
    }

    function handleClearSelection() {
        setRowSelection({});
        setIsSelectingAll(false);
    }

    function handleSelectAll() {
        setIsSelectingAll(true);
    }

    const renderTransactionRow = useCallback(
        (
            row: Row<DecryptedTransaction>,
            virtualRow: VirtualItem,
            rowVirtualizer: Virtualizer<HTMLDivElement, Element>,
        ) => {
            return (
                <TransactionRowComponent
                    key={row.id}
                    row={row}
                    virtualRow={virtualRow}
                    rowVirtualizer={rowVirtualizer}
                    onEdit={setEditTransaction}
                    onReEvaluateRules={handleReEvaluateRules}
                    onDelete={setDeleteTransaction}
                />
            );
        },
        [handleReEvaluateRules],
    );

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Transactions')} />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title={__('Transactions')}
                    description={__('View and manage your transactions')}
                />

                <div className="space-y-4">
                    <TransactionFiltersComponent
                        filters={filters}
                        onFiltersChange={handleFiltersChange}
                        categories={categories}
                        labels={labels}
                        accounts={accounts}
                        isKeySet={true}
                        enableSavedFilters={true}
                        actions={
                            <div className="flex w-full items-center justify-between gap-2">
                                <TransactionActionsMenu
                                    categories={categories}
                                    accounts={accounts}
                                    banks={banks}
                                    automationRules={automationRules}
                                    onAddTransaction={() =>
                                        setCreateDialogOpen(true)
                                    }
                                    transactions={allTransactions}
                                    onReEvaluateComplete={() => {
                                        setRowSelection({});
                                        refreshTransactions();
                                    }}
                                    onImportComplete={() =>
                                        refreshTransactions()
                                    }
                                    filters={filters}
                                />

                                <DataTableViewOptions table={table} />
                            </div>
                        }
                    />

                    {isNavigating ? (
                        <div className="space-y-4">
                            <div className="overflow-hidden rounded-md border">
                                <div className="grid grid-cols-4 gap-4 border-b p-4">
                                    <Skeleton className="h-5 w-8" />
                                    <Skeleton className="h-5 w-24" />
                                    <Skeleton className="h-5 w-64" />
                                    <Skeleton className="h-5 w-20 justify-self-end" />
                                </div>
                                <div className="divide-y">
                                    <div className="bg-muted/50 px-4 py-2">
                                        <Skeleton className="h-4 w-32" />
                                    </div>
                                    {Array.from({ length: 6 }).map(
                                        (_, index) => (
                                            <div
                                                key={index}
                                                className="grid grid-cols-4 gap-4 p-4"
                                            >
                                                <Skeleton className="h-4 w-8" />
                                                <Skeleton className="h-4 w-28" />
                                                <div className="space-y-2">
                                                    <Skeleton className="h-4 w-full" />
                                                    <Skeleton className="h-3 w-48" />
                                                </div>
                                                <Skeleton className="h-4 w-20 justify-self-end" />
                                            </div>
                                        ),
                                    )}
                                </div>
                            </div>
                            <div className="flex items-center justify-between">
                                <Skeleton className="h-5 w-32" />
                                <Skeleton className="h-9 w-48" />
                            </div>
                        </div>
                    ) : (
                        <>
                            <DataTable
                                table={table}
                                columns={columns}
                                emptyMessage={__('No transactions found.')}
                                renderRow={renderTransactionRow}
                                getRowDate={(row) => row.transaction_date}
                                renderDateHeader={(date, colSpan) => (
                                    <DateHeader date={date} colSpan={colSpan} />
                                )}
                            />

                            <DataTablePagination
                                displayedCount={allTransactions.length}
                                rowCountLabel={__('transactions loaded')}
                            >
                                {nextCursor && (
                                    <div ref={loadMoreRef}>
                                        <Button
                                            onClick={handleLoadMore}
                                            disabled={isLoadingMore}
                                            variant="outline"
                                        >
                                            {isLoadingMore ? (
                                                <>
                                                    <Spinner />
                                                    {__('Loading')}
                                                </>
                                            ) : (
                                                <>{__('Load more')}</>
                                            )}
                                        </Button>
                                    </div>
                                )}
                            </DataTablePagination>
                        </>
                    )}
                </div>
            </div>

            <EditTransactionDialog
                transaction={editTransaction}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={labels}
                automationRules={automationRules}
                open={!!editTransaction}
                onOpenChange={(open) => !open && setEditTransaction(null)}
                onSuccess={updateTransaction}
                onCategorized={showAutomatizeToast}
                onLabelCreated={handleLabelCreated}
                mode="edit"
            />

            <EditTransactionDialog
                transaction={null}
                categories={categories}
                accounts={accounts}
                banks={banks}
                labels={labels}
                automationRules={automationRules}
                open={createDialogOpen}
                onOpenChange={setCreateDialogOpen}
                onSuccess={() => refreshTransactions()}
                onLabelCreated={handleLabelCreated}
                mode="create"
            />

            <AutomateCategorizationDialog
                open={automateDialogOpen}
                candidate={automateCandidate}
                categories={categories}
                onOpenChange={setAutomateDialogOpen}
            />

            <PostSaveApplyRulePrompt />

            <AlertDialog
                open={!!deleteTransaction && !isBulkDeleteMode}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setUpdateBalanceOnDelete(true);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Delete Transaction')}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {__(
                                'Are you sure you want to delete this transaction? This action cannot be undone.',
                            )}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    {deleteTransaction !== null &&
                        manualAccountIds.has(deleteTransaction.account_id) && (
                            <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
                                <Checkbox
                                    checked={updateBalanceOnDelete}
                                    onCheckedChange={(checked) =>
                                        setUpdateBalanceOnDelete(
                                            checked === true,
                                        )
                                    }
                                    className="mt-0.5"
                                />
                                <span className="text-muted-foreground">
                                    {__(
                                        'Update the current balance of the manual account to reflect this change.',
                                    )}
                                </span>
                            </label>
                        )}
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            disabled={isDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isDeleting ? __('Deleting...') : __('Delete')}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <Dialog
                open={!!deleteTransaction && isBulkDeleteMode}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setIsBulkDeleteMode(false);
                        setBulkDeleteConfirmation('');
                        setUpdateBalanceOnDelete(true);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{__('Delete Transactions')}</DialogTitle>
                        <DialogDescription>
                            {__(
                                'This action cannot be undone. To confirm, type',
                            )}{' '}
                            <span className="font-semibold text-foreground">
                                {bulkDeleteConfirmationText}
                            </span>
                        </DialogDescription>
                    </DialogHeader>
                    <Input
                        value={bulkDeleteConfirmation}
                        onChange={(e) =>
                            setBulkDeleteConfirmation(e.target.value)
                        }
                        placeholder={bulkDeleteConfirmationText}
                        disabled={isBulkDeleting}
                        autoFocus
                    />
                    {bulkDeleteTouchesManualAccount && (
                        <label className="flex cursor-pointer items-start gap-3 rounded-md border border-border bg-muted/40 p-3 text-sm">
                            <Checkbox
                                checked={updateBalanceOnDelete}
                                onCheckedChange={(checked) =>
                                    setUpdateBalanceOnDelete(checked === true)
                                }
                                className="mt-0.5"
                            />
                            <span className="text-muted-foreground">
                                {__(
                                    'Update the current balance of the affected manual accounts to reflect this change.',
                                )}
                            </span>
                        </label>
                    )}
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => {
                                setDeleteTransaction(null);
                                setIsBulkDeleteMode(false);
                                setBulkDeleteConfirmation('');
                            }}
                            disabled={isBulkDeleting}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button
                            variant="destructive"
                            onClick={handleBulkDelete}
                            disabled={
                                isBulkDeleting ||
                                bulkDeleteConfirmation !==
                                    bulkDeleteConfirmationText
                            }
                        >
                            {isBulkDeleting ? __('Deleting...') : __('Delete')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <BulkActionsBar
                selectedCount={selectedCount}
                isSelectingAll={isSelectingAll}
                categories={categories}
                labels={labels}
                onCategoryChange={handleBulkCategoryChange}
                onLabelsChange={handleBulkLabelsChange}
                onLabelCreated={handleLabelCreated}
                onDelete={handleBulkDeleteClick}
                onReEvaluateRules={handleBulkReEvaluateRules}
                onSelectAll={handleSelectAll}
                onClear={handleClearSelection}
                isUpdating={isBulkUpdating || isReEvaluating}
            />
        </AppSidebarLayout>
    );
}

import { useLocale } from '@/hooks/use-locale';
import { __ } from '@/utils/i18n';
import {
    Cell,
    ColumnFiltersState,
    Row,
    SortingState,
    VisibilityState,
    flexRender,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { VirtualItem, Virtualizer } from '@tanstack/react-virtual';
import axios from 'axios';
import { format, getYear, isWithinInterval, parseISO } from 'date-fns';
import {
    type ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useState,
} from 'react';
import { toast } from 'sonner';

import { single as reEvaluateSingle } from '@/actions/App/Http/Controllers/ReEvaluateTransactionRulesController';
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
import { Skeleton } from '@/components/ui/skeleton';
import { Spinner } from '@/components/ui/spinner';
import { TableCell, TableRow } from '@/components/ui/table';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { consoleDebug } from '@/lib/debug';
import { db } from '@/lib/dexie-db';
import { getStoredKey } from '@/lib/key-storage';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
    type Transaction,
} from '@/types/transaction';
import { UUID } from '@/types/uuid';

const COLUMN_VISIBILITY_KEY = 'transactions-column-visibility';

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
                                    className={meta?.cellClassName}
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
        <tr className="hidden bg-muted/50">
            <td
                colSpan={colSpan}
                className="px-4 py-2 text-sm font-semibold text-muted-foreground"
            >
                {format(parsedDate, formatString)}
            </td>
        </tr>
    );
}

export interface TransactionListProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels?: Label[];
    automationRules?: AutomationRule[];
    accountId?: UUID;
    transactions?: Transaction[]; // Optional: if provided, use these instead of fetching from Dexie
    pageSize?: number;
    hideAccountFilter?: boolean;
    showActionsMenu?: boolean;
    headerActions?: ReactNode;
    maxHeight?: number;
    hideColumns?: string[];
}

export function TransactionList({
    categories,
    accounts,
    banks,
    labels: initialLabels = [],
    automationRules = [],
    accountId,
    transactions: providedTransactions,
    pageSize = 25,
    hideAccountFilter = false,
    showActionsMenu = true,
    headerActions,
    maxHeight,
    hideColumns = [],
}: TransactionListProps) {
    const { isKeySet } = useEncryptionKey();
    const locale = useLocale();
    const labels = initialLabels;

    const [transactions, setTransactions] = useState<DecryptedTransaction[]>(
        [],
    );
    const [isLoading, setIsLoading] = useState(true);
    const [refreshKey, setRefreshKey] = useState(0);
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>(
        getInitialColumnVisibility(),
    );
    const [rowSelection, setRowSelection] = useState({});
    const [filters, setFilters] = useState<Filters>({
        dateFrom: null,
        dateTo: null,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        accountIds: accountId ? [accountId] : [],
        labelIds: [],
        searchText: '',
    });
    const [editTransaction, setEditTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [createDialogOpen, setCreateDialogOpen] = useState(false);
    const [deleteTransaction, setDeleteTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [isBulkDeleteMode, setIsBulkDeleteMode] = useState(false);
    const [isDeleting, setIsDeleting] = useState(false);
    const [isBulkDeleting, setIsBulkDeleting] = useState(false);
    const [isBulkUpdating, setIsBulkUpdating] = useState(false);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const [displayedCount, setDisplayedCount] = useState(pageSize);
    const [isLoadingMore, setIsLoadingMore] = useState(false);

    const updateTransaction = useCallback(
        (updatedTransaction: DecryptedTransaction) => {
            setTransactions((previous) =>
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
        [setTransactions],
    );

    useEffect(() => {
        async function processTransactions() {
            // If transactions are provided directly, use them as-is (already decrypted from backend)
            if (providedTransactions) {
                setIsLoading(true);
                try {
                    const keyString = getStoredKey();
                    let key: CryptoKey | null = null;

                    if (keyString && isKeySet) {
                        try {
                            key = await importKey(keyString);
                        } catch (error) {
                            console.error(
                                'Failed to import encryption key:',
                                error,
                            );
                        }
                    }

                    const decrypted = await Promise.all(
                        providedTransactions.map(async (transaction) => {
                            try {
                                let decryptedDescription = '';
                                let decryptedNotes: string | null = null;

                                if (!transaction.description_iv) {
                                    decryptedDescription =
                                        transaction.description;
                                    decryptedNotes = transaction.notes || null;
                                } else if (key) {
                                    try {
                                        decryptedDescription = await decrypt(
                                            transaction.description,
                                            key,
                                            transaction.description_iv,
                                        );
                                        if (
                                            transaction.notes &&
                                            transaction.notes_iv
                                        ) {
                                            decryptedNotes = await decrypt(
                                                transaction.notes,
                                                key,
                                                transaction.notes_iv,
                                            );
                                        }
                                    } catch (error) {
                                        console.error(
                                            'Failed to decrypt transaction:',
                                            error,
                                        );
                                    }
                                }

                                return {
                                    ...transaction,
                                    decryptedDescription,
                                    decryptedNotes,
                                } as DecryptedTransaction;
                            } catch (error) {
                                console.error(
                                    'Error processing transaction:',
                                    error,
                                );
                                return null;
                            }
                        }),
                    );

                    const validTransactions = decrypted.filter(
                        (t): t is DecryptedTransaction => t !== null,
                    );

                    setTransactions(validTransactions);
                } catch (error) {
                    console.error('Error processing transactions:', error);
                } finally {
                    setIsLoading(false);
                }
                return;
            }

            setIsLoading(true);
            try {
                const response = await axios.get('/api/sync/transactions');
                const serverData = response.data.data || response.data;

                if (!Array.isArray(serverData)) {
                    throw new Error('Invalid server response format');
                }

                let filteredServerData = serverData;
                if (accountId) {
                    filteredServerData = serverData.filter(
                        (t: Transaction) => t.account_id === accountId,
                    );
                }

                const accountsMap = new Map(
                    accounts.map((account) => [account.id, account]),
                );
                const categoriesMap = new Map(
                    categories.map((category) => [category.id, category]),
                );
                const banksMap = new Map(banks.map((bank) => [bank.id, bank]));

                const keyString = getStoredKey();
                let key: CryptoKey | null = null;

                if (keyString && isKeySet) {
                    try {
                        key = await importKey(keyString);
                    } catch (error) {
                        console.error(
                            'Failed to import encryption key:',
                            error,
                        );
                    }
                }

                const transformedTransactions = filteredServerData.map(
                    (serverRecord: Transaction) => {
                        const label_ids = serverRecord.labels?.map(
                            (l: { id: string }) => l.id,
                        );

                        // eslint-disable-next-line @typescript-eslint/no-unused-vars
                        const { labels: _labels, ...rest } = serverRecord;

                        return {
                            ...rest,
                            transaction_date: String(
                                serverRecord.transaction_date,
                            ).slice(0, 10),
                            label_ids: label_ids || [],
                        } as Transaction;
                    },
                );

                const decrypted = await Promise.all(
                    transformedTransactions.map(async (transaction) => {
                        try {
                            let decryptedDescription = '';
                            let decryptedNotes: string | null = null;

                            if (!transaction.description_iv) {
                                decryptedDescription = transaction.description;
                                decryptedNotes = transaction.notes || null;
                            } else if (key) {
                                try {
                                    decryptedDescription = await decrypt(
                                        transaction.description,
                                        key,
                                        transaction.description_iv,
                                    );

                                    if (
                                        transaction.notes &&
                                        transaction.notes_iv
                                    ) {
                                        decryptedNotes = await decrypt(
                                            transaction.notes,
                                            key,
                                            transaction.notes_iv,
                                        );
                                    }
                                } catch (error) {
                                    console.error(
                                        'Failed to decrypt transaction:',
                                        transaction.id,
                                        error,
                                    );
                                }
                            }

                            const account = accountsMap.get(
                                transaction.account_id,
                            );
                            const category = transaction.category_id
                                ? categoriesMap.get(transaction.category_id)
                                : null;
                            const bank = account?.bank?.id
                                ? banksMap.get(account.bank.id)
                                : undefined;

                            return {
                                ...transaction,
                                decryptedDescription,
                                decryptedNotes,
                                account,
                                category: category || null,
                                bank,
                            } as DecryptedTransaction;
                        } catch (error) {
                            console.error(
                                'Failed to process transaction:',
                                transaction.id,
                                error,
                            );
                            return null;
                        }
                    }),
                );

                const validTransactions = decrypted.filter(
                    (transaction): transaction is DecryptedTransaction =>
                        transaction !== null,
                );

                validTransactions.sort((a, b) => {
                    const dateA = parseISO(a.transaction_date).getTime();
                    const dateB = parseISO(b.transaction_date).getTime();
                    return dateB - dateA;
                });

                setTransactions(validTransactions);
            } catch (error) {
                console.error('Failed to load transactions:', error);
            } finally {
                setIsLoading(false);
            }
        }

        processTransactions();
    }, [
        refreshKey,
        accounts,
        banks,
        categories,
        isKeySet,
        accountId,
        providedTransactions,
    ]);

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

    useEffect(() => {
        async function reDecryptTransactions() {
            if (transactions.length === 0) {
                return;
            }

            const keyString = getStoredKey();
            let key: CryptoKey | null = null;

            if (keyString && isKeySet) {
                try {
                    key = await importKey(keyString);
                } catch (error) {
                    console.error('Failed to import encryption key:', error);
                }
            }

            const reDecrypted = await Promise.all(
                transactions.map(async (transaction) => {
                    try {
                        let decryptedDescription = '';
                        let decryptedNotes: string | null = null;

                        if (!transaction.description_iv) {
                            decryptedDescription = transaction.description;
                            decryptedNotes = transaction.notes || null;
                        } else if (key) {
                            try {
                                decryptedDescription = await decrypt(
                                    transaction.description,
                                    key,
                                    transaction.description_iv,
                                );

                                if (transaction.notes && transaction.notes_iv) {
                                    decryptedNotes = await decrypt(
                                        transaction.notes,
                                        key,
                                        transaction.notes_iv,
                                    );
                                }
                            } catch (error) {
                                console.error(
                                    'Failed to decrypt transaction:',
                                    transaction.id,
                                    error,
                                );
                            }
                        }

                        return {
                            ...transaction,
                            decryptedDescription,
                            decryptedNotes,
                        } as DecryptedTransaction;
                    } catch (error) {
                        console.error(
                            'Failed to process transaction:',
                            transaction.id,
                            error,
                        );
                        return transaction;
                    }
                }),
            );

            setTransactions(reDecrypted);
        }

        reDecryptTransactions();
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [isKeySet]);

    const [searchMatchedIds, setSearchMatchedIds] = useState<Set<string>>(
        new Set(),
    );
    const [isSearching, setIsSearching] = useState(false);

    useEffect(() => {
        async function searchInIndexedDB() {
            if (!filters.searchText || !isKeySet) {
                setSearchMatchedIds(new Set());
                setIsSearching(false);
                return;
            }

            setIsSearching(true);
            try {
                const keyString = getStoredKey();
                if (!keyString) {
                    setSearchMatchedIds(new Set());
                    return;
                }

                const key = await importKey(keyString);
                const searchLower = filters.searchText.toLowerCase();

                let allIndexedTransactions = await db.transactions.toArray();

                if (accountId) {
                    allIndexedTransactions = allIndexedTransactions.filter(
                        (t) => t.account_id === accountId,
                    );
                }

                const matchedIds = new Set<string>();

                for (const tx of allIndexedTransactions) {
                    try {
                        let decryptedDescription = '';
                        let decryptedNotes: string | null = null;

                        try {
                            if (!tx.description_iv) {
                                decryptedDescription = tx.description;
                                decryptedNotes = tx.notes || null;
                            } else {
                                decryptedDescription = await decrypt(
                                    tx.description,
                                    key,
                                    tx.description_iv,
                                );

                                if (tx.notes && tx.notes_iv) {
                                    decryptedNotes = await decrypt(
                                        tx.notes,
                                        key,
                                        tx.notes_iv,
                                    );
                                }
                            }
                        } catch {
                            continue;
                        }

                        const matchesDescription = decryptedDescription
                            .toLowerCase()
                            .includes(searchLower);
                        const matchesNotes =
                            decryptedNotes
                                ?.toLowerCase()
                                .includes(searchLower) || false;

                        if (matchesDescription || matchesNotes) {
                            matchedIds.add(tx.id);
                        }
                    } catch {
                        continue;
                    }
                }

                setSearchMatchedIds(matchedIds);
            } catch (error) {
                console.error('Failed to search in IndexedDB:', error);
                setSearchMatchedIds(new Set());
            } finally {
                setIsSearching(false);
            }
        }

        searchInIndexedDB();
    }, [filters.searchText, isKeySet, accountId]);

    const filteredTransactions = useMemo(() => {
        return transactions.filter((transaction) => {
            if (filters.searchText && isKeySet) {
                if (!searchMatchedIds.has(transaction.id)) {
                    return false;
                }
            } else if (filters.searchText && !isKeySet) {
                return false;
            }

            if (filters.dateFrom || filters.dateTo) {
                const transactionDate = parseISO(transaction.transaction_date);
                if (
                    filters.dateFrom &&
                    filters.dateTo &&
                    !isWithinInterval(transactionDate, {
                        start: filters.dateFrom,
                        end: filters.dateTo,
                    })
                ) {
                    return false;
                }
                if (filters.dateFrom && transactionDate < filters.dateFrom) {
                    return false;
                }
                if (filters.dateTo && transactionDate > filters.dateTo) {
                    return false;
                }
            }

            if (
                filters.amountMin !== null &&
                transaction.amount / 100 < filters.amountMin
            ) {
                return false;
            }
            if (
                filters.amountMax !== null &&
                transaction.amount / 100 > filters.amountMax
            ) {
                return false;
            }

            if (
                filters.categoryIds.length > 0 &&
                !filters.categoryIds.includes(transaction.category_id || -1)
            ) {
                return false;
            }

            if (
                !accountId &&
                filters.accountIds.length > 0 &&
                !filters.accountIds.includes(transaction.account_id)
            ) {
                return false;
            }

            return true;
        });
    }, [transactions, filters, isKeySet, accountId, searchMatchedIds]);

    const sortedTransactions = useMemo(() => {
        if (sorting.length === 0) {
            return filteredTransactions;
        }

        const sorted = [...filteredTransactions];
        sorted.sort((a, b) => {
            for (const sort of sorting) {
                const { id, desc } = sort;
                let comparison = 0;

                if (id === 'transaction_date') {
                    const dateA = parseISO(a.transaction_date).getTime();
                    const dateB = parseISO(b.transaction_date).getTime();
                    comparison = dateA - dateB;
                } else if (id === 'amount') {
                    comparison = parseFloat(a.amount) - parseFloat(b.amount);
                } else if (id === 'description') {
                    comparison = a.decryptedDescription.localeCompare(
                        b.decryptedDescription,
                    );
                } else if (id === 'account') {
                    const accountA = a.account?.name || '';
                    const accountB = b.account?.name || '';
                    comparison = accountA.localeCompare(accountB);
                } else if (id === 'category') {
                    const categoryA = a.category?.name || '';
                    const categoryB = b.category?.name || '';
                    comparison = categoryA.localeCompare(categoryB);
                }

                if (comparison !== 0) {
                    return desc ? -comparison : comparison;
                }
            }
            return 0;
        });

        return sorted;
    }, [filteredTransactions, sorting]);

    const displayedTransactions = useMemo(() => {
        return sortedTransactions.slice(0, displayedCount);
    }, [sortedTransactions, displayedCount]);

    const handleReEvaluateRules = useCallback(
        async (transaction: DecryptedTransaction) => {
            consoleDebug('=== Re-evaluating rules for single transaction ===');

            setIsReEvaluating(true);
            try {
                const response = await axios.post<{
                    data: DecryptedTransaction;
                }>(reEvaluateSingle({ transaction: transaction.id }).url);

                const updated = response.data.data;

                updateTransaction({
                    ...transaction,
                    category_id: updated.category_id,
                    category: updated.category,
                    labels: updated.labels,
                    notes: updated.notes,
                    notes_iv: updated.notes_iv,
                });
                consoleDebug('✓ UI state updated successfully');
            } catch (error) {
                consoleDebug('❌ Error during re-evaluation:', error);
                console.error('Failed to re-evaluate rules:', error);
            } finally {
                setIsReEvaluating(false);
                consoleDebug('=== Re-evaluation complete ===');
            }
        },
        [categories, accounts, banks, updateTransaction, automationRules],
    );

    async function handleBulkReEvaluateRules() {
        const selectedIds = Object.keys(rowSelection);
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');
        consoleDebug(`Selected ${selectedIds.length} transactions`);

        if (selectedIds.length === 0) {
            consoleDebug('❌ No transactions selected');
            return;
        }

        setIsReEvaluating(true);
        const toastId = toast.loading(`Re-evaluating 0 of ... transactions...`);

        try {
            const bulkResponse = await axios.post<{ job_id: string }>(
                reEvaluateBulk().url,
                { transaction_ids: selectedIds },
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
            consoleDebug('=== Bulk re-evaluation complete ===');
        } catch (error) {
            consoleDebug('❌ Error during bulk re-evaluation:', error);
            console.error('Failed to re-evaluate rules:', error);
            toast.error('Failed to re-evaluate rules. Please try again.', {
                id: toastId,
            });
        } finally {
            setIsReEvaluating(false);
        }
    }

    const columns = useMemo(() => {
        const allColumns = createTransactionColumns({
            categories,
            accounts,
            banks,
            labels,
            locale,
            onEdit: setEditTransaction,
            onDelete: setDeleteTransaction,
            onUpdate: updateTransaction,
            onReEvaluateRules: handleReEvaluateRules,
        });

        if (hideColumns.length === 0) {
            return allColumns;
        }

        return allColumns.filter((column) => {
            const columnId =
                'accessorKey' in column ? column.accessorKey : column.id;
            return !hideColumns.includes(columnId as string);
        });
    }, [
        accounts,
        banks,
        categories,
        labels,
        locale,
        updateTransaction,
        handleReEvaluateRules,
        hideColumns,
    ]);

    const table = useReactTable({
        data: displayedTransactions,
        columns,
        onSortingChange: setSorting,
        onColumnFiltersChange: setColumnFilters,
        getRowId: (row) => row.id.toString(),
        getCoreRowModel: getCoreRowModel(),
        getSortedRowModel: getSortedRowModel(),
        getFilteredRowModel: getFilteredRowModel(),
        onColumnVisibilityChange: setColumnVisibility,
        onRowSelectionChange: setRowSelection,
        enableRowSelection: true,
        state: {
            sorting,
            columnFilters,
            columnVisibility,
            rowSelection,
        },
    });

    const loadMore = useCallback(() => {
        if (displayedCount < sortedTransactions.length && !isLoadingMore) {
            setIsLoadingMore(true);
            requestAnimationFrame(() => {
                setDisplayedCount((prev) =>
                    Math.min(prev + pageSize, sortedTransactions.length),
                );
                requestAnimationFrame(() => {
                    setIsLoadingMore(false);
                });
            });
        }
    }, [displayedCount, sortedTransactions.length, isLoadingMore, pageSize]);

    useEffect(() => {
        setDisplayedCount(pageSize);
    }, [filters, sorting, pageSize]);

    async function handleDelete() {
        if (!deleteTransaction) {
            return;
        }

        setIsDeleting(true);
        try {
            await transactionSyncService.delete(deleteTransaction.id);
            setTransactions((previous) =>
                previous.filter(
                    (transaction) => transaction.id !== deleteTransaction.id,
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
            setRefreshKey((prev) => prev + 1);
        } catch (error) {
            console.error('Failed to delete transaction:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    async function handleBulkCategoryChange(categoryId: number | null) {
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to update transactions',
            );
            return;
        }

        const selectedIds = Object.keys(rowSelection);
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            await transactionSyncService.updateMany(selectedIds, {
                category_id: categoryId,
            });

            const categoriesMap = new Map(
                categories.map((category) => [category.id, category]),
            );
            const selectedCategory = categoryId
                ? categoriesMap.get(categoryId) || null
                : null;

            setTransactions((previous) =>
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

            setRowSelection({});
            setRefreshKey((prev) => prev + 1);
        } catch (error) {
            console.error('Failed to update transactions:', error);
        } finally {
            setIsBulkUpdating(false);
        }
    }

    function handleBulkDeleteClick() {
        const selectedIds = Object.keys(rowSelection);

        if (selectedIds.length === 0) {
            return;
        }

        const firstSelectedTransaction = filteredTransactions.find(
            (t) => t.id.toString() === selectedIds[0],
        );

        if (firstSelectedTransaction) {
            setIsBulkDeleteMode(true);
            setDeleteTransaction(firstSelectedTransaction);
        }
    }

    async function handleBulkDelete() {
        const selectedIds = Object.keys(rowSelection);
        if (selectedIds.length === 0) {
            return;
        }

        setIsBulkDeleting(true);
        try {
            await transactionSyncService.deleteMany(selectedIds);
            setTransactions((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
            setRowSelection({});
            setRefreshKey((prev) => prev + 1);
        } catch (error) {
            console.error('Failed to delete transactions:', error);
        } finally {
            setIsBulkDeleting(false);
        }
    }

    function handleClearSelection() {
        setRowSelection({});
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
        <>
            <div className="space-y-4">
                <TransactionFiltersComponent
                    filters={filters}
                    onFiltersChange={setFilters}
                    categories={categories}
                    labels={labels}
                    accounts={accounts}
                    isKeySet={isKeySet}
                    hideAccountFilter={hideAccountFilter}
                    actions={
                        <div className="flex justify-end gap-2">
                            {showActionsMenu && (
                                <TransactionActionsMenu
                                    categories={categories}
                                    accounts={accounts}
                                    banks={banks}
                                    onAddTransaction={() =>
                                        setCreateDialogOpen(true)
                                    }
                                    transactions={transactions}
                                    onReEvaluateComplete={() => {
                                        setRowSelection({});
                                        setTimeout(() => {
                                            window.location.reload();
                                        }, 500);
                                    }}
                                />
                            )}
                            {headerActions}
                            <DataTableViewOptions
                                table={table}
                                hideColumnTextOnMobile={false}
                            />
                        </div>
                    }
                />

                {isLoading || isSearching ? (
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
                                {Array.from({ length: 6 }).map((_, index) => (
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
                                ))}
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
                            maxHeight={maxHeight}
                            getRowDate={(row) => row.transaction_date}
                            renderDateHeader={(date, colSpan) => (
                                <DateHeader date={date} colSpan={colSpan} />
                            )}
                        />

                        <DataTablePagination
                            displayedCount={displayedCount}
                            total={sortedTransactions.length}
                            rowCountLabel={__('transactions total')}
                        >
                            {displayedCount < sortedTransactions.length && (
                                <Button
                                    onClick={loadMore}
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
                            )}
                        </DataTablePagination>
                    </>
                )}
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
                onSuccess={() => {}}
                mode="create"
            />

            <AlertDialog
                open={!!deleteTransaction}
                onOpenChange={(open) => {
                    if (!open) {
                        setDeleteTransaction(null);
                        setIsBulkDeleteMode(false);
                    }
                }}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>
                            {__('Delete Transaction')}

                            {isBulkDeleteMode ? 's' : ''}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {isBulkDeleteMode
                                ? `Are you sure you want to delete ${Object.keys(rowSelection).length} transactions? This action cannot be undone.`
                                : 'Are you sure you want to delete this transaction? This action cannot be undone.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            disabled={isDeleting || isBulkDeleting}
                        >
                            {__('Cancel')}
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={
                                isBulkDeleteMode
                                    ? handleBulkDelete
                                    : handleDelete
                            }
                            disabled={isDeleting || isBulkDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isDeleting || isBulkDeleting
                                ? 'Deleting...'
                                : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <BulkActionsBar
                selectedCount={Object.keys(rowSelection).length}
                categories={categories}
                onCategoryChange={handleBulkCategoryChange}
                onDelete={handleBulkDeleteClick}
                onReEvaluateRules={handleBulkReEvaluateRules}
                onClear={handleClearSelection}
                isUpdating={isBulkUpdating || isReEvaluating}
            />
        </>
    );
}

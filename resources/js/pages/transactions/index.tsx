import { Head } from '@inertiajs/react';
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
import { format, getYear, isWithinInterval, parseISO } from 'date-fns';
import { useLiveQuery } from 'dexie-react-hooks';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
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
import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { decrypt, encrypt, importKey } from '@/lib/crypto';
import { consoleDebug } from '@/lib/debug';
import { db } from '@/lib/dexie-db';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRules } from '@/lib/rule-engine';
import { appendNoteIfNotPresent, cn } from '@/lib/utils';
import { automationRuleSyncService } from '@/services/automation-rule-sync';
import { transactionSyncService } from '@/services/transaction-sync';
import { type BreadcrumbItem } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
} from '@/types/transaction';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Transactions',
        href: transactionsIndex.url(),
    },
];

interface Props {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    labels: Label[];
}

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
                <ContextMenuLabel>Actions</ContextMenuLabel>
                <ContextMenuItem onClick={() => onEdit(transaction)}>
                    Edit
                </ContextMenuItem>
                <ContextMenuItem onClick={() => onReEvaluateRules(transaction)}>
                    Re-evaluate rules
                </ContextMenuItem>
                <ContextMenuItem
                    onClick={() => onDelete(transaction)}
                    variant="destructive"
                >
                    Delete
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
    categories,
    accounts,
    banks,
    labels: initialLabels,
}: Props) {
    const { isKeySet } = useEncryptionKey();

    const transactionIds = useLiveQuery(
        async () => {
            const txs = await db.transactions.toArray();
            return txs
                .map((t) => `${t.id}:${t.updated_at}`)
                .sort()
                .join(',');
        },
        [],
        '',
    );

    const [transactions, setTransactions] = useState<DecryptedTransaction[]>(
        [],
    );
    const [isLoading, setIsLoading] = useState(true);
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
        accountIds: [],
        labelIds: [],
        searchText: '',
    });
    const labels = useLiveQuery(() => db.labels.toArray(), [], initialLabels);
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
    const [displayedCount, setDisplayedCount] = useState(25);
    const [isLoadingMore, setIsLoadingMore] = useState(false);
    const [isSelectingAll, setIsSelectingAll] = useState(false);

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
            if (transactionIds === undefined) {
                setIsLoading(true);
                return;
            }

            setIsLoading(true);
            try {
                const rawTransactions = await db.transactions.toArray();

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

                const decrypted = await Promise.all(
                    rawTransactions.map(async (transaction) => {
                        try {
                            let decryptedDescription = '';
                            let decryptedNotes: string | null = null;

                            if (key) {
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

                            // Map label_ids to Label objects
                            const transactionLabels =
                                transaction.label_ids
                                    ?.map((labelId) =>
                                        labels.find((l) => l.id === labelId),
                                    )
                                    .filter(
                                        (label): label is Label =>
                                            label !== undefined,
                                    ) || [];

                            return {
                                ...transaction,
                                decryptedDescription,
                                decryptedNotes,
                                account,
                                category: category || null,
                                bank,
                                labels: transactionLabels,
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
    }, [transactionIds, accounts, banks, categories, labels, isKeySet]);

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

                        if (key) {
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

    const filteredTransactions = useMemo(() => {
        return transactions.filter((transaction) => {
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
                filters.accountIds.length > 0 &&
                !filters.accountIds.includes(transaction.account_id)
            ) {
                return false;
            }

            if (filters.labelIds.length > 0) {
                const transactionLabelIds =
                    transaction.labels?.map((l) => l.id) || [];
                const hasMatchingLabel = filters.labelIds.some((labelId) =>
                    transactionLabelIds.includes(labelId),
                );
                if (!hasMatchingLabel) {
                    return false;
                }
            }

            if (filters.searchText && isKeySet) {
                const searchLower = filters.searchText.toLowerCase();
                const matchesDescription = transaction.decryptedDescription
                    .toLowerCase()
                    .includes(searchLower);
                const matchesNotes =
                    transaction.decryptedNotes
                        ?.toLowerCase()
                        .includes(searchLower) || false;

                if (!matchesDescription && !matchesNotes) {
                    return false;
                }
            }

            return true;
        });
    }, [transactions, filters, isKeySet]);

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
            consoleDebug('Transaction:', {
                id: transaction.id,
                description: transaction.decryptedDescription,
                amount: transaction.amount,
                currentCategory: transaction.category?.name || 'None',
            });

            setIsReEvaluating(true);
            try {
                const keyString = getStoredKey();
                if (!keyString || !isKeySet) {
                    consoleDebug('❌ Encryption key not set');
                    console.error('Encryption key not set');
                    toast.error(
                        'Please unlock your encryption key to re-evaluate rules',
                    );
                    return;
                }
                consoleDebug('✓ Encryption key found');

                const key = await importKey(keyString);
                const rules = await automationRuleSyncService.getAll();
                consoleDebug(`Found ${rules.length} automation rules`);

                if (rules.length === 0) {
                    consoleDebug('❌ No rules to evaluate');
                    return;
                }

                consoleDebug('Evaluating rules against transaction...');
                const result = await evaluateRules(
                    transaction,
                    rules,
                    categories,
                    accounts,
                    banks,
                    key,
                );

                consoleDebug('Rule evaluation result:', result);

                if (result) {
                    consoleDebug('✓ Rule matched! Applying changes...');
                    let finalNotes = transaction.notes;
                    let finalNotesIv = transaction.notes_iv;

                    if (result.note && result.noteIv) {
                        consoleDebug('Adding note from rule');
                        const decryptedRuleNote = await decrypt(
                            result.note,
                            key,
                            result.noteIv,
                        );
                        const combinedNote = appendNoteIfNotPresent(
                            transaction.decryptedNotes,
                            decryptedRuleNote,
                        );

                        if (combinedNote !== transaction.decryptedNotes) {
                            const encrypted = await encrypt(combinedNote, key);
                            finalNotes = encrypted.encrypted;
                            finalNotesIv = encrypted.iv;
                            consoleDebug('Combined notes with rule note');
                        } else {
                            consoleDebug('Rule note already present, skipping');
                        }
                    }

                    const updateData = {
                        category_id: result.categoryId,
                        notes: finalNotes,
                        notes_iv: finalNotesIv,
                    };
                    consoleDebug('Updating transaction with:', updateData);

                    await transactionSyncService.update(
                        transaction.id,
                        updateData,
                    );
                    consoleDebug('✓ Transaction updated in IndexedDB');

                    const selectedCategory = result.categoryId
                        ? categories.find((c) => c.id === result.categoryId) ||
                          null
                        : null;

                    let decryptedNotes = transaction.decryptedNotes;
                    if (finalNotes && finalNotesIv) {
                        decryptedNotes = await decrypt(
                            finalNotes,
                            key,
                            finalNotesIv,
                        );
                    }

                    const updatedTransaction = {
                        ...transaction,
                        category_id: result.categoryId,
                        category: selectedCategory,
                        notes: finalNotes,
                        notes_iv: finalNotesIv,
                        decryptedNotes,
                    };
                    consoleDebug('Updating UI state with:', {
                        id: updatedTransaction.id,
                        newCategory: selectedCategory?.name || 'None',
                        hasNotes: !!decryptedNotes,
                    });

                    updateTransaction(updatedTransaction);
                    consoleDebug('✓ UI state updated successfully');
                } else {
                    consoleDebug('❌ No rules matched this transaction');
                }
            } catch (error) {
                consoleDebug('❌ Error during re-evaluation:', error);
                console.error('Failed to re-evaluate rules:', error);
            } finally {
                setIsReEvaluating(false);
                consoleDebug('=== Re-evaluation complete ===');
            }
        },
        [isKeySet, categories, accounts, banks, updateTransaction],
    );

    async function handleBulkReEvaluateRules() {
        const BATCH_SIZE = 25;
        consoleDebug('=== Re-evaluating rules for bulk transactions ===');
        consoleDebug(`Selected ${selectedIds.length} transactions`);

        if (selectedIds.length === 0) {
            consoleDebug('❌ No transactions selected');
            return;
        }

        setIsReEvaluating(true);
        try {
            const keyString = getStoredKey();
            if (!keyString || !isKeySet) {
                consoleDebug('❌ Encryption key not set');
                console.error('Encryption key not set');
                toast.error(
                    'Please unlock your encryption key to re-evaluate rules',
                );
                return;
            }
            consoleDebug('✓ Encryption key found');

            const key = await importKey(keyString);
            const rules = await automationRuleSyncService.getAll();
            consoleDebug(`Found ${rules.length} automation rules`);

            if (rules.length === 0) {
                consoleDebug('❌ No rules to evaluate');
                return;
            }

            const selectedTransactions = transactions.filter((t) =>
                selectedIds.includes(t.id.toString()),
            );
            consoleDebug(
                'Processing transactions:',
                selectedTransactions.map((t) => ({
                    id: t.id,
                    description: t.decryptedDescription,
                    currentCategory: t.category?.name || 'None',
                })),
            );

            // Collect all updates first without updating IndexedDB
            const allUpdates: Array<{
                transaction: DecryptedTransaction;
                categoryId: number | null;
                category: Category | null;
                notes: string | null;
                notesIv: string | null;
                decryptedNotes: string | null;
            }> = [];

            const dbUpdates: Array<{
                id: string;
                data: {
                    category_id: number | null;
                    notes: string | null;
                    notes_iv: string | null;
                };
            }> = [];

            for (const transaction of selectedTransactions) {
                consoleDebug(`\nEvaluating transaction ${transaction.id}...`);
                const result = await evaluateRules(
                    transaction,
                    rules,
                    categories,
                    accounts,
                    banks,
                    key,
                );

                consoleDebug('Rule evaluation result:', result);

                if (result) {
                    consoleDebug('✓ Rule matched! Applying changes...');
                    let finalNotes = transaction.notes;
                    let finalNotesIv = transaction.notes_iv;

                    if (result.note && result.noteIv) {
                        consoleDebug('Adding note from rule');
                        const decryptedRuleNote = await decrypt(
                            result.note,
                            key,
                            result.noteIv,
                        );
                        const combinedNote = appendNoteIfNotPresent(
                            transaction.decryptedNotes,
                            decryptedRuleNote,
                        );

                        if (combinedNote !== transaction.decryptedNotes) {
                            const encrypted = await encrypt(combinedNote, key);
                            finalNotes = encrypted.encrypted;
                            finalNotesIv = encrypted.iv;
                            consoleDebug('Combined notes with rule note');
                        } else {
                            consoleDebug('Rule note already present, skipping');
                        }
                    }

                    const updateData = {
                        category_id: result.categoryId,
                        notes: finalNotes,
                        notes_iv: finalNotesIv,
                    };
                    consoleDebug('Queuing update for transaction:', updateData);

                    // Collect for batch IndexedDB update
                    dbUpdates.push({
                        id: transaction.id,
                        data: updateData,
                    });

                    const selectedCategory = result.categoryId
                        ? categories.find((c) => c.id === result.categoryId) ||
                          null
                        : null;

                    let decryptedNotes = transaction.decryptedNotes;
                    if (finalNotes && finalNotesIv) {
                        decryptedNotes = await decrypt(
                            finalNotes,
                            key,
                            finalNotesIv,
                        );
                    }

                    allUpdates.push({
                        transaction,
                        categoryId: result.categoryId,
                        category: selectedCategory,
                        notes: finalNotes,
                        notesIv: finalNotesIv,
                        decryptedNotes,
                    });
                    consoleDebug(
                        `✓ Queued update for transaction ${transaction.id}`,
                    );
                } else {
                    consoleDebug(
                        `❌ No rules matched transaction ${transaction.id}`,
                    );
                }
            }

            // Batch update IndexedDB
            if (dbUpdates.length > 0) {
                consoleDebug(
                    `\nBatch updating ${dbUpdates.length} transactions in IndexedDB...`,
                );
                await transactionSyncService.updateManyIndividual(dbUpdates);
                consoleDebug('✓ IndexedDB batch update complete');
            }

            // Update UI state in batches
            consoleDebug(
                `\nApplying ${allUpdates.length} updates to UI state in batches of ${BATCH_SIZE}...`,
            );
            if (allUpdates.length > 0) {
                for (let i = 0; i < allUpdates.length; i += BATCH_SIZE) {
                    const batch = allUpdates.slice(i, i + BATCH_SIZE);
                    const batchIds = new Set(
                        batch.map((u) => u.transaction.id),
                    );

                    consoleDebug(
                        `Applying batch ${Math.floor(i / BATCH_SIZE) + 1} (${batch.length} updates)...`,
                    );

                    setTransactions((previous) =>
                        previous.map((transaction) => {
                            if (!batchIds.has(transaction.id)) {
                                return transaction;
                            }
                            const update = batch.find(
                                (u) => u.transaction.id === transaction.id,
                            );
                            if (update) {
                                return {
                                    ...transaction,
                                    category_id: update.categoryId,
                                    category: update.category,
                                    notes: update.notes,
                                    notes_iv: update.notesIv,
                                    decryptedNotes: update.decryptedNotes,
                                };
                            }
                            return transaction;
                        }),
                    );

                    // Small delay between batches to allow React to process renders
                    if (i + BATCH_SIZE < allUpdates.length) {
                        await new Promise((resolve) => setTimeout(resolve, 50));
                    }
                }
                consoleDebug('✓ UI state updated successfully');
            } else {
                consoleDebug('❌ No updates to apply');
            }

            consoleDebug('Clearing selection...');
            setRowSelection({});
        } catch (error) {
            consoleDebug('❌ Error during bulk re-evaluation:', error);
            console.error('Failed to re-evaluate rules:', error);
        } finally {
            setIsReEvaluating(false);
            consoleDebug('=== Bulk re-evaluation complete ===');
        }
    }

    const columns = useMemo(
        () =>
            createTransactionColumns({
                categories,
                accounts,
                banks,
                labels,
                onEdit: setEditTransaction,
                onDelete: setDeleteTransaction,
                onUpdate: updateTransaction,
                onReEvaluateRules: handleReEvaluateRules,
            }),
        [
            accounts,
            banks,
            categories,
            labels,
            updateTransaction,
            handleReEvaluateRules,
        ],
    );

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
                    Math.min(prev + 25, sortedTransactions.length),
                );
                requestAnimationFrame(() => {
                    setIsLoadingMore(false);
                });
            });
        }
    }, [displayedCount, sortedTransactions.length, isLoadingMore]);

    useEffect(() => {
        setDisplayedCount(25);
    }, [filters, sorting]);

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

        if (selectedIds.length === 0 && !isSelectingAll) {
            return;
        }

        setIsBulkUpdating(true);
        try {
            const categoriesMap = new Map(
                categories.map((category) => [category.id, category]),
            );
            const selectedCategory = categoryId
                ? categoriesMap.get(categoryId) || null
                : null;

            if (isSelectingAll) {
                // Update via filters
                await transactionSyncService.updateByFilters(filters, {
                    category_id: categoryId,
                });

                // Optimistically update matching transactions in state
                setTransactions((previous) =>
                    previous.map((transaction) => ({
                        ...transaction,
                        category_id: categoryId,
                        category: selectedCategory,
                    })),
                );

                toast.success(
                    `Updated ${sortedTransactions.length} transactions`,
                );
            } else {
                // Update selected transactions
                await transactionSyncService.updateMany(selectedIds, {
                    category_id: categoryId,
                });

                // Optimistically update selected transactions in state
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

                toast.success(`Updated ${selectedIds.length} transactions`);
            }

            setRowSelection({});
            setIsSelectingAll(false);
        } catch (error) {
            console.error('Failed to update transactions:', error);
            toast.error('Failed to update transactions');
        } finally {
            setIsBulkUpdating(false);
        }
    }

    const selectedIds = useMemo(
        () => Object.keys(rowSelection),
        [rowSelection],
    );

    const selectedCount = useMemo(() => selectedIds.length, [selectedIds]);

    function handleBulkDeleteClick() {
        if (selectedIds.length === 0) {
            return;
        }

        // Defer the find operation until delete is actually clicked
        const firstSelectedTransaction = filteredTransactions.find(
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
            await transactionSyncService.deleteMany(selectedIds);
            setTransactions((previous) =>
                previous.filter(
                    (transaction) => !selectedIds.includes(transaction.id),
                ),
            );
            setDeleteTransaction(null);
            setIsBulkDeleteMode(false);
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
            const selectedLabels = labels.filter((l) =>
                labelIds.includes(l.id),
            );

            if (isSelectingAll) {
                // Update via filters
                await transactionSyncService.updateByFilters(filters, {
                    label_ids: labelIds,
                });

                // Optimistically update matching transactions in state
                setTransactions((previous) =>
                    previous.map((transaction) => {
                        // If labelIds is empty, remove all labels
                        if (labelIds.length === 0) {
                            return {
                                ...transaction,
                                labels: [],
                            };
                        }

                        // Otherwise, merge with existing labels
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
                    }),
                );

                toast.success(
                    `Updated ${sortedTransactions.length} transactions`,
                );
            } else {
                // Update selected transactions
                await transactionSyncService.updateMany(selectedIds, {
                    label_ids: labelIds,
                });

                // Optimistically update selected transactions in state
                setTransactions((previous) =>
                    previous.map((transaction) => {
                        if (selectedIds.includes(transaction.id.toString())) {
                            // If labelIds is empty, remove all labels
                            if (labelIds.length === 0) {
                                return {
                                    ...transaction,
                                    labels: [],
                                };
                            }

                            // Otherwise, merge with existing labels
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

                toast.success(`Updated ${selectedIds.length} transactions`);
            }

            setRowSelection({});
            setIsSelectingAll(false);
        } catch (error) {
            console.error('Failed to update transactions with labels:', error);
            toast.error('Failed to update transactions with labels');
        } finally {
            setIsBulkUpdating(false);
        }
    }

    function handleClearSelection() {
        setRowSelection({});
        setIsSelectingAll(false);
    }

    const handleSelectAll = useCallback(() => {
        setIsSelectingAll(true);
        // Use requestAnimationFrame to defer the expensive reduce operation
        requestAnimationFrame(() => {
            const allIds = sortedTransactions.reduce(
                (acc, transaction) => {
                    acc[transaction.id.toString()] = true;
                    return acc;
                },
                {} as Record<string, boolean>,
            );
            setRowSelection(allIds);
        });
    }, [sortedTransactions]);

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
            <Head title="Transactions" />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title="Transactions"
                    description="View and manage your transactions"
                />

                <div className="space-y-4">
                    <TransactionFiltersComponent
                        filters={filters}
                        onFiltersChange={setFilters}
                        categories={categories}
                        labels={labels}
                        accounts={accounts}
                        isKeySet={isKeySet}
                        actions={
                            <div className="flex w-full justify-between gap-2 sm:justify-end">
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
                                <DataTableViewOptions table={table} />
                            </div>
                        }
                    />

                    {isLoading ? (
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
                                emptyMessage="No transactions found."
                                renderRow={renderTransactionRow}
                                getRowDate={(row) => row.transaction_date}
                                renderDateHeader={(date, colSpan) => (
                                    <DateHeader date={date} colSpan={colSpan} />
                                )}
                            />

                            <DataTablePagination
                                displayedCount={displayedCount}
                                total={sortedTransactions.length}
                                rowCountLabel="transactions total"
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
                                                Loading
                                            </>
                                        ) : (
                                            <>Load more</>
                                        )}
                                    </Button>
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
                            Delete Transaction
                            {isBulkDeleteMode ? 's' : ''}
                        </AlertDialogTitle>
                        <AlertDialogDescription>
                            {isBulkDeleteMode
                                ? `Are you sure you want to delete ${selectedCount} transactions? This action cannot be undone.`
                                : 'Are you sure you want to delete this transaction? This action cannot be undone.'}
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel
                            disabled={isDeleting || isBulkDeleting}
                        >
                            Cancel
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
                selectedCount={selectedCount}
                totalFilteredCount={sortedTransactions.length}
                isSelectingAll={isSelectingAll}
                categories={categories}
                labels={labels}
                onCategoryChange={handleBulkCategoryChange}
                onLabelsChange={handleBulkLabelsChange}
                onDelete={handleBulkDeleteClick}
                onReEvaluateRules={handleBulkReEvaluateRules}
                onSelectAll={handleSelectAll}
                onClear={handleClearSelection}
                isUpdating={isBulkUpdating || isReEvaluating}
            />
        </AppSidebarLayout>
    );
}

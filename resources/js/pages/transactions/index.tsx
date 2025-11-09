import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { Head } from '@inertiajs/react';
import {
    ColumnFiltersState,
    SortingState,
    VisibilityState,
    getCoreRowModel,
    getFilteredRowModel,
    getSortedRowModel,
    useReactTable,
} from '@tanstack/react-table';
import { parseISO, isWithinInterval } from 'date-fns';

import AppSidebarLayout from '@/layouts/app/app-sidebar-layout';
import { DataTable } from '@/components/ui/data-table';
import HeadingSmall from '@/components/heading-small';
import { DataTablePagination } from '@/components/ui/data-table-pagination';
import { Skeleton } from '@/components/ui/skeleton';
import { TransactionFilters } from '@/components/transactions/transaction-filters';
import { EditTransactionDialog } from '@/components/transactions/edit-transaction-dialog';
import { createTransactionColumns } from '@/components/transactions/transaction-columns';
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
import { type Category } from '@/types/category';
import { type Account, type Bank } from '@/types/account';
import {
    type DecryptedTransaction,
    type TransactionFilters as Filters,
} from '@/types/transaction';
import { type BreadcrumbItem } from '@/types';
import { transactionSyncService } from '@/services/transaction-sync';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';

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
}

export default function Transactions({ categories, accounts, banks }: Props) {
    const { isKeySet } = useEncryptionKey();
    const [transactions, setTransactions] = useState<DecryptedTransaction[]>([]);
    const [isLoading, setIsLoading] = useState(true);
    const [sorting, setSorting] = useState<SortingState>([
        { id: 'transaction_date', desc: true },
    ]);
    const [columnFilters, setColumnFilters] = useState<ColumnFiltersState>([]);
    const [columnVisibility, setColumnVisibility] = useState<VisibilityState>({
        account: false,
    });
    const [filters, setFilters] = useState<Filters>({
        dateFrom: null,
        dateTo: null,
        amountMin: null,
        amountMax: null,
        categoryIds: [],
        accountIds: [],
        searchText: '',
    });
    const [editTransaction, setEditTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [deleteTransaction, setDeleteTransaction] =
        useState<DecryptedTransaction | null>(null);
    const [isDeleting, setIsDeleting] = useState(false);
    const [displayedCount, setDisplayedCount] = useState(25);
    const observerTarget = useRef<HTMLDivElement>(null);

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
                                ? transaction.category ?? null
                                : updatedTransaction.category,
                    };
                }),
            );
        },
        [setTransactions],
    );

    const loadTransactions = useCallback(async () => {
        setIsLoading(true);
        try {
            const rawTransactions = await transactionSyncService.getAll();
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
                    console.error('Failed to import encryption key:', error);
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

                        const account = accountsMap.get(transaction.account_id);
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

            setTransactions(
                decrypted.filter(
                    (transaction): transaction is DecryptedTransaction =>
                        transaction !== null,
                ),
            );
        } catch (error) {
            console.error('Failed to load transactions:', error);
        } finally {
            setIsLoading(false);
        }
    }, [accounts, banks, categories, isKeySet]);

    useEffect(() => {
        loadTransactions();
    }, [loadTransactions]);

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
                parseFloat(transaction.amount) < filters.amountMin
            ) {
                return false;
            }
            if (
                filters.amountMax !== null &&
                parseFloat(transaction.amount) > filters.amountMax
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

            if (filters.searchText && isKeySet) {
                const searchLower = filters.searchText.toLowerCase();
                const matchesDescription =
                    transaction.decryptedDescription
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

    const displayedTransactions = useMemo(() => {
        return filteredTransactions.slice(0, displayedCount);
    }, [filteredTransactions, displayedCount]);

    const columns = useMemo(
        () =>
            createTransactionColumns({
                categories,
                accounts,
                banks,
                onEdit: setEditTransaction,
                onDelete: setDeleteTransaction,
                onUpdate: updateTransaction,
            }),
        [accounts, banks, categories, updateTransaction],
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
        state: {
            sorting,
            columnFilters,
            columnVisibility,
        },
    });

    const loadMore = useCallback(() => {
        if (displayedCount < filteredTransactions.length) {
            setDisplayedCount((prev) => Math.min(prev + 25, filteredTransactions.length));
        }
    }, [displayedCount, filteredTransactions.length]);

    useEffect(() => {
        const observer = new IntersectionObserver(
            (entries) => {
                if (entries[0].isIntersecting) {
                    loadMore();
                }
            },
            { threshold: 0.1 }
        );

        const currentTarget = observerTarget.current;
        if (currentTarget) {
            observer.observe(currentTarget);
        }

        return () => {
            if (currentTarget) {
                observer.unobserve(currentTarget);
            }
        };
    }, [loadMore]);

    useEffect(() => {
        setDisplayedCount(25);
    }, [filters]);

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
        } catch (error) {
            console.error('Failed to delete transaction:', error);
        } finally {
            setIsDeleting(false);
        }
    }

    return (
        <AppSidebarLayout breadcrumbs={breadcrumbs}>
            <Head title="Transactions" />

            <div className="space-y-6 p-6">
                <HeadingSmall
                    title="Transactions"
                    description="View and manage your transactions"
                />

                <div className="space-y-4">
                    <TransactionFilters
                        filters={filters}
                        onFiltersChange={setFilters}
                        categories={categories}
                        accounts={accounts}
                        isKeySet={isKeySet}
                    />

                    {isLoading ? (
                        <div className="space-y-4">
                            <div className="overflow-hidden rounded-md border">
                                <div className="grid grid-cols-6 gap-4 border-b p-4">
                                    <Skeleton className="h-5 w-24" />
                                    <Skeleton className="h-5 w-28" />
                                    <Skeleton className="h-5 w-64" />
                                    <Skeleton className="h-5 w-28" />
                                    <Skeleton className="h-5 w-28" />
                                    <Skeleton className="h-5 w-16 justify-self-end" />
                                </div>
                                <div className="divide-y">
                                    {Array.from({ length: 6 }).map((_, index) => (
                                        <div
                                            key={index}
                                            className="grid grid-cols-6 gap-4 p-4"
                                        >
                                            <Skeleton className="h-4 w-32" />
                                            <Skeleton className="h-4 w-36" />
                                            <Skeleton className="h-4 w-full" />
                                            <Skeleton className="h-4 w-32" />
                                            <Skeleton className="h-4 w-40" />
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
                                emptyMessage="No transactions found."
                            />

                            <DataTablePagination
                                table={table}
                                displayedCount={displayedCount}
                                total={filteredTransactions.length}
                                rowCountLabel="transactions total"
                            />

                            <div ref={observerTarget} className="h-0" />
                        </>
                    )}
                </div>
            </div>

            <EditTransactionDialog
                transaction={editTransaction}
                categories={categories}
                open={!!editTransaction}
                onOpenChange={(open) => !open && setEditTransaction(null)}
                onSuccess={updateTransaction}
            />

            <AlertDialog
                open={!!deleteTransaction}
                onOpenChange={(open) => !open && setDeleteTransaction(null)}
            >
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Delete Transaction</AlertDialogTitle>
                        <AlertDialogDescription>
                            Are you sure you want to delete this transaction?
                            This action cannot be undone.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={isDeleting}>
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            onClick={handleDelete}
                            disabled={isDeleting}
                            className="bg-red-600 hover:bg-red-700"
                        >
                            {isDeleting ? 'Deleting...' : 'Delete'}
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppSidebarLayout>
    );
}


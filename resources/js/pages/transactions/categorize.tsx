import { categorize as categorizeRoute } from '@/actions/App/Http/Controllers/TransactionController';
import { AutomationRulesDialog } from '@/components/automation-rules/automation-rules-dialog';
import { EncryptedText } from '@/components/encrypted-text';
import { CategoryIcon } from '@/components/shared/category-combobox';
import { Button } from '@/components/ui/button';
import {
    Command,
    CommandEmpty,
    CommandGroup,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import { Kbd } from '@/components/ui/kbd';
import { Skeleton } from '@/components/ui/skeleton';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { db } from '@/lib/dexie-db';
import { getStoredKey } from '@/lib/key-storage';
import { evaluateRules } from '@/lib/rule-engine';
import { cn } from '@/lib/utils';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category, getCategoryColorClasses } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { Head, Link, router } from '@inertiajs/react';
import { parseISO } from 'date-fns';
import { useLiveQuery } from 'dexie-react-hooks';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    CheckCircle2,
    PartyPopper,
    Settings2,
    SkipBack,
    SkipForward,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';

const CATEGORY_USAGE_KEY = 'category-usage-order';

interface Props {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
}

type AnimationState = 'idle' | 'exiting' | 'entering' | 'success';

function getCategoryUsageOrder(): string[] {
    try {
        const stored = localStorage.getItem(CATEGORY_USAGE_KEY);
        if (stored) {
            return JSON.parse(stored);
        }
    } catch {
        // Ignore errors
    }
    return [];
}

function updateCategoryUsageOrder(categoryId: string): void {
    try {
        const current = getCategoryUsageOrder();
        const filtered = current.filter((id) => id !== categoryId);
        const updated = [categoryId, ...filtered];
        localStorage.setItem(CATEGORY_USAGE_KEY, JSON.stringify(updated));
    } catch {
        // Ignore errors
    }
}

function sortCategoriesByUsage(
    categories: Category[],
    usageOrder: string[],
): Category[] {
    const orderMap = new Map(usageOrder.map((id, index) => [id, index]));

    return [...categories].sort((a, b) => {
        const aIndex = orderMap.get(a.id) ?? Infinity;
        const bIndex = orderMap.get(b.id) ?? Infinity;

        if (aIndex === bIndex) {
            return a.name.localeCompare(b.name);
        }

        return aIndex - bIndex;
    });
}

export default function CategorizeTransactions({
    categories,
    accounts,
    banks,
}: Props) {
    const { isKeySet } = useEncryptionKey();

    const transactionIds = useLiveQuery(
        async () => {
            const txs = await db.transactions.toArray();
            return txs
                .map((t) => t.id)
                .sort()
                .join(',');
        },
        [],
        '',
    );

    const [uncategorizedTransactions, setUncategorizedTransactions] = useState<
        DecryptedTransaction[]
    >([]);
    const [currentIndex, setCurrentIndex] = useState(0);
    const [isLoading, setIsLoading] = useState(true);
    const [animationState, setAnimationState] =
        useState<AnimationState>('idle');
    const [categoryUsageOrder, setCategoryUsageOrder] = useState<string[]>([]);
    const [lastSelectedCategory, setLastSelectedCategory] =
        useState<Category | null>(null);
    const [searchValue, setSearchValue] = useState('');
    const [rulesDialogOpen, setRulesDialogOpen] = useState(false);
    const [encryptionKey, setEncryptionKey] = useState<CryptoKey | null>(null);
    const commandInputRef = useRef<HTMLInputElement>(null);

    const automationRules = useLiveQuery(
        async () => {
            const rules = await db.automation_rules.toArray();
            return rules.map((rule) => ({
                ...rule,
                rules_json:
                    typeof rule.rules_json === 'string'
                        ? JSON.parse(rule.rules_json)
                        : rule.rules_json,
            })) as AutomationRule[];
        },
        [],
        [],
    );

    useEffect(() => {
        setCategoryUsageOrder(getCategoryUsageOrder());
    }, []);

    useEffect(() => {
        if (!isLoading && animationState === 'idle') {
            commandInputRef.current?.focus();
        }
    }, [isLoading, animationState, currentIndex]);

    useEffect(() => {
        async function loadUncategorizedTransactions() {
            if (transactionIds === undefined) {
                setIsLoading(true);
                return;
            }

            setIsLoading(true);
            try {
                const rawTransactions = await db.transactions.toArray();

                const uncategorized = rawTransactions.filter(
                    (t) => !t.category_id,
                );

                const accountsMap = new Map(
                    accounts.map((account) => [account.id, account]),
                );
                const banksMap = new Map(banks.map((bank) => [bank.id, bank]));

                const keyString = getStoredKey();
                let key: CryptoKey | null = null;

                if (keyString && isKeySet) {
                    try {
                        key = await importKey(keyString);
                        setEncryptionKey(key);
                    } catch (error) {
                        console.error(
                            'Failed to import encryption key:',
                            error,
                        );
                    }
                }

                const decrypted = await Promise.all(
                    uncategorized.map(async (transaction) => {
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
                            const bank = account?.bank?.id
                                ? banksMap.get(account.bank.id)
                                : undefined;

                            return {
                                ...transaction,
                                decryptedDescription,
                                decryptedNotes,
                                account,
                                category: null,
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

                setUncategorizedTransactions(validTransactions);
            } catch (error) {
                console.error(
                    'Failed to load uncategorized transactions:',
                    error,
                );
            } finally {
                setIsLoading(false);
            }
        }

        loadUncategorizedTransactions();
    }, [transactionIds, accounts, banks, isKeySet]);

    const currentTransaction = uncategorizedTransactions[currentIndex];
    const remainingCount = uncategorizedTransactions.length - currentIndex;
    const isComplete = currentIndex >= uncategorizedTransactions.length;

    const sortedCategories = useMemo(() => {
        return sortCategoriesByUsage(categories, categoryUsageOrder);
    }, [categories, categoryUsageOrder]);

    const applyAutomationRulesToQueue = useCallback(async () => {
        if (
            !encryptionKey ||
            !automationRules.length ||
            uncategorizedTransactions.length === 0
        ) {
            return;
        }

        const remainingTransactions =
            uncategorizedTransactions.slice(currentIndex);
        let categorizedCount = 0;
        const newUncategorizedList: DecryptedTransaction[] = [
            ...uncategorizedTransactions.slice(0, currentIndex),
        ];

        for (const transaction of remainingTransactions) {
            const result = await evaluateRules(
                transaction,
                automationRules,
                categories,
                accounts,
                banks,
                encryptionKey,
            );

            if (result?.categoryId) {
                try {
                    await transactionSyncService.update(transaction.id, {
                        category_id: result.categoryId,
                    });
                    categorizedCount++;

                    const matchedCategory = categories.find(
                        (c) => c.id === result.categoryId,
                    );
                    if (matchedCategory) {
                        updateCategoryUsageOrder(matchedCategory.id);
                    }
                } catch (error) {
                    console.error(
                        'Failed to apply automation rule to transaction:',
                        error,
                    );
                    newUncategorizedList.push(transaction);
                }
            } else {
                newUncategorizedList.push(transaction);
            }
        }

        if (categorizedCount > 0) {
            setCategoryUsageOrder(getCategoryUsageOrder());
            setUncategorizedTransactions(newUncategorizedList);
            setCurrentIndex(
                Math.min(currentIndex, newUncategorizedList.length),
            );
        }

        return categorizedCount;
    }, [
        encryptionKey,
        automationRules,
        uncategorizedTransactions,
        currentIndex,
        categories,
        accounts,
        banks,
    ]);

    const handleRulesDialogClose = useCallback(
        async (open: boolean) => {
            setRulesDialogOpen(open);

            if (!open) {
                await applyAutomationRulesToQueue();
                commandInputRef.current?.focus();
            }
        },
        [applyAutomationRulesToQueue],
    );

    const handleCategorySelect = useCallback(
        async (category: Category) => {
            if (!currentTransaction || animationState !== 'idle') return;

            setLastSelectedCategory(category);
            setAnimationState('exiting');

            try {
                await transactionSyncService.update(currentTransaction.id, {
                    category_id: category.id,
                });

                updateCategoryUsageOrder(category.id);
                setCategoryUsageOrder(getCategoryUsageOrder());
            } catch (error) {
                console.error('Failed to update transaction:', error);
                setAnimationState('idle');
                return;
            }

            setTimeout(() => {
                setAnimationState('success');

                setTimeout(() => {
                    setCurrentIndex((prev) => prev + 1);
                    setAnimationState('entering');
                    setSearchValue('');

                    setTimeout(() => {
                        setAnimationState('idle');
                        setLastSelectedCategory(null);
                        commandInputRef.current?.focus();
                    }, 300);
                }, 400);
            }, 300);
        },
        [currentTransaction, animationState],
    );

    const handleSkip = useCallback(() => {
        if (animationState !== 'idle') return;

        setAnimationState('exiting');

        setTimeout(() => {
            setCurrentIndex((prev) => prev + 1);
            setAnimationState('entering');

            setTimeout(() => {
                setAnimationState('idle');
            }, 300);
        }, 300);
    }, [animationState]);

    const handlePrevious = useCallback(() => {
        if (animationState !== 'idle' || currentIndex === 0) return;

        setAnimationState('exiting');

        setTimeout(() => {
            setCurrentIndex((prev) => prev - 1);
            setAnimationState('entering');

            setTimeout(() => {
                setAnimationState('idle');
            }, 300);
        }, 300);
    }, [animationState, currentIndex]);

    // Keyboard shortcuts
    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            // Ctrl + R for rules dialog
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                setRulesDialogOpen(true);
            }
            // Ctrl + N for skip (next)
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                if (animationState === 'idle' && currentTransaction) {
                    handleSkip();
                }
            }
            // Ctrl + B for previous (back)
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                if (animationState === 'idle' && currentIndex > 0) {
                    handlePrevious();
                }
            }
            // Escape to go back to transactions
            if (e.key === 'Escape' && !rulesDialogOpen) {
                e.preventDefault();
                const transactionsUrl = categorizeRoute
                    .url()
                    ?.replace('/categorize', '');
                if (transactionsUrl) {
                    router.visit(transactionsUrl);
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [
        animationState,
        currentTransaction,
        handleSkip,
        handlePrevious,
        currentIndex,
        rulesDialogOpen,
    ]);

    const formatAmount = (amount: number, currencyCode: string): string => {
        return new Intl.NumberFormat('en-US', {
            style: 'currency',
            currency: currencyCode,
        }).format(amount / 100);
    };

    const formatDate = (dateStr: string): string => {
        const date = new Date(dateStr);
        return date.toLocaleDateString('en-US', {
            weekday: 'short',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
        });
    };

    if (isLoading) {
        return (
            <>
                <Head title="Categorize Transactions" />
                <div className="flex min-h-screen flex-col items-center justify-center bg-white p-4 dark:bg-zinc-950">
                    <div className="w-full max-w-lg space-y-6">
                        <Skeleton className="mx-auto h-6 w-32" />
                        <Skeleton className="h-48 w-full rounded-2xl" />
                        <div className="grid grid-cols-3 gap-3">
                            {Array.from({ length: 9 }).map((_, i) => (
                                <Skeleton key={i} className="h-12 rounded-xl" />
                            ))}
                        </div>
                    </div>
                </div>
            </>
        );
    }

    if (isComplete) {
        return (
            <>
                <Head title="All Done!" />
                <div className="flex min-h-screen flex-col items-center justify-center bg-white p-4 dark:bg-zinc-950">
                    <div className="animate-bounce-in flex flex-col items-center gap-6 text-center">
                        <div className="relative">
                            <div className="absolute inset-0 animate-ping rounded-full bg-emerald-400/30" />
                            <div className="relative flex h-24 w-24 items-center justify-center rounded-full bg-gradient-to-br from-emerald-400 to-teal-500 shadow-lg shadow-emerald-500/30">
                                <PartyPopper className="h-12 w-12 text-white" />
                            </div>
                        </div>
                        <div className="space-y-2">
                            <h1 className="text-3xl font-bold text-zinc-900 dark:text-zinc-100">
                                All Done!
                            </h1>
                            <p className="text-lg text-zinc-600 dark:text-zinc-400">
                                You've categorized all your transactions.
                            </p>
                        </div>
                        <Link
                            href={categorizeRoute
                                .url()
                                ?.replace('/categorize', '')}
                        >
                            <Button size="lg" className="mt-4">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Transactions
                            </Button>
                        </Link>
                    </div>
                </div>
            </>
        );
    }

    if (uncategorizedTransactions.length === 0) {
        return (
            <>
                <Head title="Categorize Transactions" />
                <div className="flex min-h-screen flex-col items-center justify-center bg-white p-4 dark:bg-zinc-950">
                    <div className="flex flex-col items-center gap-6 text-center">
                        <div className="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                            <CheckCircle2 className="h-10 w-10 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div className="space-y-2">
                            <h1 className="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                No Uncategorized Transactions
                            </h1>
                            <p className="text-zinc-600 dark:text-zinc-400">
                                All your transactions are already categorized.
                            </p>
                        </div>
                        <Link
                            href={categorizeRoute
                                .url()
                                ?.replace('/categorize', '')}
                        >
                            <Button>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                Back to Transactions
                            </Button>
                        </Link>
                    </div>
                </div>
            </>
        );
    }

    return (
        <>
            <Head title="Categorize Transactions" />

            <div className="flex min-h-screen flex-col bg-white dark:bg-zinc-950">
                <header className="flex items-center justify-between px-4 py-3 dark:border-zinc-800">
                    <Link
                        href={categorizeRoute.url()?.replace('/categorize', '')}
                        className="hidden sm:flex items-center gap-2 text-sm text-zinc-600 opacity-50 transition-all duration-200 hover:text-zinc-900 hover:opacity-100 dark:text-zinc-400 dark:hover:text-zinc-100"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        Back to Transactions
                    </Link>
                    <div className="flex items-center gap-3">
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => setRulesDialogOpen(true)}
                            className="gap-2 pr-2"
                        >
                            <Settings2 className="h-4 w-4" />
                            Rules
                            <Kbd>Ctrl+R</Kbd>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={handlePrevious}
                            disabled={
                                animationState !== 'idle' || currentIndex === 0
                            }
                            className="gap-2 pr-2 text-muted-foreground"
                        >
                            <SkipBack className="h-4 w-4" />
                            <span className="hidden sm:inline">Prev</span>
                            <Kbd>Ctrl+B</Kbd>
                        </Button>
                        <Button
                            variant="ghost"
                            size="sm"
                            onClick={handleSkip}
                            disabled={
                                animationState !== 'idle' || !currentTransaction
                            }
                            className="gap-2 pr-2 text-muted-foreground"
                        >
                            <SkipForward className="h-4 w-4" />
                            <span className="hidden sm:inline">Skip</span>
                            <Kbd>Ctrl+N</Kbd>
                        </Button>
                        <div className="hidden sm:flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                            <span className="font-medium text-zinc-900 dark:text-zinc-100">
                                {remainingCount}
                            </span>
                            remaining
                        </div>
                    </div>
                </header>

                <main className="flex flex-1 flex-col items-center justify-center p-4">
                    <div className="w-full max-w-xl space-y-8">
                        <div className="relative">
                            {animationState === 'success' &&
                                lastSelectedCategory && (
                                    <div className="flex items-center justify-center py-8">
                                        <div className="animate-success-pop flex flex-col items-center gap-3">
                                            <div
                                                className={cn(
                                                    'flex h-16 w-16 items-center justify-center rounded-full',
                                                    getCategoryColorClasses(
                                                        lastSelectedCategory.color,
                                                    ).bg,
                                                )}
                                            >
                                                <CheckCircle2
                                                    className={cn(
                                                        'h-8 w-8',
                                                        getCategoryColorClasses(
                                                            lastSelectedCategory.color,
                                                        ).text,
                                                    )}
                                                />
                                            </div>
                                            <span className="text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                                {lastSelectedCategory.name}
                                            </span>
                                        </div>
                                    </div>
                                )}

                            {animationState !== 'success' &&
                                currentTransaction && (
                                    <div
                                        className={cn(
                                            'rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl shadow-zinc-200/50 transition-all duration-300 dark:border-zinc-800 dark:bg-zinc-900 dark:shadow-zinc-900/50',
                                            animationState === 'exiting' &&
                                            'translate-y-[-20px] scale-95 opacity-0',
                                            animationState === 'entering' &&
                                            'animate-card-enter',
                                            animationState === 'idle' &&
                                            'translate-y-0 scale-100 opacity-100',
                                        )}
                                    >
                                        <div className="flex flex-col gap-4">
                                            <div className="space-y-6">
                                                <div className="flex items-start justify-between">
                                                    <div className="flex flex-1 flex-col gap-4">
                                                        <p className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                                                            {formatDate(
                                                                currentTransaction.transaction_date,
                                                            )}
                                                        </p>

                                                        <h2 className="text-2xl whitespace-pre-wrap text-zinc-900 dark:text-zinc-100">
                                                            {currentTransaction.decryptedDescription ||
                                                                'Encrypted'}
                                                        </h2>

                                                        {currentTransaction.account && (
                                                            <div className="flex items-center gap-2">
                                                                {currentTransaction
                                                                    .bank
                                                                    ?.logo && (
                                                                        <img
                                                                            src={
                                                                                currentTransaction
                                                                                    .bank
                                                                                    .logo
                                                                            }
                                                                            alt={
                                                                                currentTransaction
                                                                                    .bank
                                                                                    .name
                                                                            }
                                                                            className="h-5 w-5 rounded"
                                                                        />
                                                                    )}
                                                                <EncryptedText
                                                                    encryptedText={
                                                                        currentTransaction
                                                                            .account
                                                                            .name
                                                                    }
                                                                    iv={
                                                                        currentTransaction
                                                                            .account
                                                                            .name_iv
                                                                    }
                                                                    length={{
                                                                        min: 5,
                                                                        max: 20,
                                                                    }}
                                                                    className="text-sm text-zinc-600 dark:text-zinc-400"
                                                                />
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>

                                                {currentTransaction.decryptedNotes && (
                                                    <p className="text-sm text-muted-foreground">
                                                        {
                                                            currentTransaction.decryptedNotes
                                                        }
                                                    </p>
                                                )}
                                            </div>

                                            <div className="mt-2 font-mono text-xl text-muted-foreground">
                                                <span
                                                    className={cn(
                                                        'rounded px-1',
                                                        currentTransaction.amount >=
                                                        0 &&
                                                        'bg-green-100/70 dark:bg-green-900',
                                                    )}
                                                >
                                                    {formatAmount(
                                                        currentTransaction.amount,
                                                        currentTransaction.currency_code,
                                                    )}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                )}
                        </div>

                        {animationState !== 'success' && currentTransaction && (
                            <div
                                className={cn(
                                    'flex flex-col gap-4 px-6 pt-2 transition-all duration-300',
                                    animationState === 'exiting' &&
                                    'translate-y-[-10px] opacity-0',
                                    animationState === 'entering' &&
                                    'animate-command-enter',
                                    animationState === 'idle' &&
                                    'translate-y-0 opacity-100',
                                )}
                            >
                                <div className="flex flex-col gap-1">
                                    <h3 className="font-medium">
                                        Assign a new category
                                    </h3>
                                    <p className="flex items-center gap-1 text-sm text-muted-foreground">
                                        Search, move{' '}
                                        <Kbd>
                                            <ArrowUp className="size-3" />
                                        </Kbd>
                                        <Kbd>
                                            <ArrowDown className="size-3" />
                                        </Kbd>
                                        , and press <Kbd>⏎</Kbd>
                                    </p>
                                </div>
                                <Command
                                    className="rounded-xl border border-zinc-200 bg-white shadow-lg dark:border-zinc-800 dark:bg-zinc-900"
                                    shouldFilter={true}
                                >
                                    <CommandInput
                                        ref={commandInputRef}
                                        placeholder="Search categories..."
                                        value={searchValue}
                                        onValueChange={setSearchValue}
                                        disabled={animationState !== 'idle'}
                                    />
                                    <CommandList className="max-h-64">
                                        <CommandEmpty>
                                            No categories found.
                                        </CommandEmpty>
                                        <CommandGroup>
                                            {sortedCategories.map(
                                                (category) => {
                                                    const colorClasses =
                                                        getCategoryColorClasses(
                                                            category.color,
                                                        );
                                                    return (
                                                        <CommandItem
                                                            key={category.id}
                                                            value={
                                                                category.name
                                                            }
                                                            onSelect={() =>
                                                                handleCategorySelect(
                                                                    category,
                                                                )
                                                            }
                                                            disabled={
                                                                animationState !==
                                                                'idle'
                                                            }
                                                            className="group cursor-pointer gap-3 p-2"
                                                        >
                                                            <div
                                                                className={cn(
                                                                    'flex size-5 shrink-0 items-center justify-center rounded-full transition-transform duration-200 group-data-[selected=true]:scale-110',
                                                                    colorClasses.bg,
                                                                )}
                                                            >
                                                                <CategoryIcon
                                                                    category={
                                                                        category
                                                                    }
                                                                />
                                                            </div>
                                                            <span className="flex-1 truncate font-medium">
                                                                {category.name}
                                                            </span>
                                                        </CommandItem>
                                                    );
                                                },
                                            )}
                                        </CommandGroup>
                                    </CommandList>
                                </Command>
                            </div>
                        )}
                    </div>
                </main>

                <div className="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <div className="mx-auto flex max-w-lg items-center gap-3">
                        <div className="h-2 flex-1 overflow-hidden rounded-full bg-zinc-100 dark:bg-zinc-800">
                            <div
                                className="h-full rounded-full bg-gradient-to-r from-emerald-400 to-teal-500 transition-all duration-500"
                                style={{
                                    width: `${(currentIndex / uncategorizedTransactions.length) * 100}%`,
                                }}
                            />
                        </div>
                        <span className="text-xs font-medium text-zinc-500 dark:text-zinc-500">
                            {currentIndex} / {uncategorizedTransactions.length}
                        </span>
                    </div>
                </div>
            </div>

            <AutomationRulesDialog
                open={rulesDialogOpen}
                onOpenChange={handleRulesDialogClose}
            />

            <style>{`
                @keyframes card-enter {
                    from {
                        opacity: 0;
                        transform: translateY(20px) scale(0.95);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0) scale(1);
                    }
                }

                @keyframes command-enter {
                    from {
                        opacity: 0;
                        transform: translateY(10px);
                    }
                    to {
                        opacity: 1;
                        transform: translateY(0);
                    }
                }

                @keyframes success-pop {
                    0% {
                        opacity: 0;
                        transform: scale(0.5);
                    }
                    50% {
                        transform: scale(1.1);
                    }
                    100% {
                        opacity: 1;
                        transform: scale(1);
                    }
                }

                @keyframes bounce-in {
                    0% {
                        opacity: 0;
                        transform: scale(0.3);
                    }
                    50% {
                        transform: scale(1.05);
                    }
                    70% {
                        transform: scale(0.9);
                    }
                    100% {
                        opacity: 1;
                        transform: scale(1);
                    }
                }

                .animate-card-enter {
                    animation: card-enter 0.3s ease-out forwards;
                }

                .animate-command-enter {
                    animation: command-enter 0.3s ease-out 0.1s forwards;
                    opacity: 0;
                }

                .animate-success-pop {
                    animation: success-pop 0.4s ease-out forwards;
                }

                .animate-bounce-in {
                    animation: bounce-in 0.6s ease-out forwards;
                }
            `}</style>
        </>
    );
}

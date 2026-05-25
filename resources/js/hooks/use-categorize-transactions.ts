import type { AutomateCategorizationCandidate } from '@/components/automation-rules/automate-categorization-dialog';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { decrypt, importKey } from '@/lib/crypto';
import { getStoredKey } from '@/lib/key-storage';
import { captureEvent } from '@/lib/posthog';
import { transactionSyncService } from '@/services/transaction-sync';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import {
    type DecryptedTransaction,
    type Transaction,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { parseISO } from 'date-fns';
import {
    createElement,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { toast } from 'sonner';

export type AnimationState = 'idle' | 'exiting' | 'entering' | 'success';

const CATEGORY_USAGE_KEY = 'category-usage-order';

export function getCategoryUsageOrder(): string[] {
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

export function updateCategoryUsageOrder(categoryId: string): void {
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

interface UseCategorizeTransactionsOptions {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    transactions: Transaction[];
}

export function useCategorizeTransactions({
    categories,
    accounts,
    banks,
    transactions: initialTransactions,
}: UseCategorizeTransactionsOptions) {
    const { isKeySet } = useEncryptionKey();

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
    const [automateDialogOpen, setAutomateDialogOpen] = useState(false);
    const [automateCandidate, setAutomateCandidate] =
        useState<AutomateCategorizationCandidate | null>(null);
    const [, setEncryptionKey] = useState<CryptoKey | null>(null);
    const [categorizedCount, setCategorizedCount] = useState(0);
    const commandInputRef = useRef<HTMLInputElement>(null);

    useEffect(() => {
        setCategoryUsageOrder(getCategoryUsageOrder());
    }, []);

    useEffect(() => {
        if (!isLoading && animationState === 'idle') {
            commandInputRef.current?.focus();
        }
    }, [isLoading, animationState, currentIndex]);

    useEffect(() => {
        async function decryptTransactions() {
            setIsLoading(true);
            try {
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
                    initialTransactions.map(async (transaction) => {
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

        decryptTransactions();
    }, [initialTransactions, accounts, banks, isKeySet]);

    const currentTransaction = uncategorizedTransactions[currentIndex];
    const remainingCount = uncategorizedTransactions.length - currentIndex;
    const isComplete = currentIndex >= uncategorizedTransactions.length;

    const sortedCategories = useMemo(() => {
        return sortCategoriesByUsage(categories, categoryUsageOrder);
    }, [categories, categoryUsageOrder]);

    const handleRulesDialogClose = useCallback((open: boolean) => {
        setRulesDialogOpen(open);

        if (!open) {
            commandInputRef.current?.focus();
        }
    }, []);

    const handleCategorySelect = useCallback(
        async (category: Category) => {
            if (!currentTransaction || animationState !== 'idle') {
                return;
            }

            const nextAutomateCandidate = {
                transaction: currentTransaction,
                category,
            };

            setLastSelectedCategory(category);
            setAnimationState('exiting');

            try {
                await transactionSyncService.update(currentTransaction.id, {
                    category_id: category.id,
                });

                updateCategoryUsageOrder(category.id);
                setCategoryUsageOrder(getCategoryUsageOrder());
                setCategorizedCount((prev) => prev + 1);
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
                                { source: 'categorize_flow' },
                            );
                            setAutomateCandidate(nextAutomateCandidate);
                            setAutomateDialogOpen(true);
                        },
                    },
                });
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
        if (animationState !== 'idle') {
            return;
        }

        setAnimationState('exiting');

        setTimeout(() => {
            setCurrentIndex((prev) => prev + 1);
            setAnimationState('entering');

            setTimeout(() => {
                setAnimationState('idle');
            }, 300);
        }, 300);
    }, [animationState]);

    const handleAutomateDialogOpenChange = useCallback((open: boolean) => {
        setAutomateDialogOpen(open);

        if (!open) {
            commandInputRef.current?.focus();
        }
    }, []);

    const handleAutomateSaved = useCallback(() => {
        setAutomateDialogOpen(false);
        commandInputRef.current?.focus();
    }, []);

    const handlePrevious = useCallback(() => {
        if (animationState !== 'idle' || currentIndex === 0) {
            return;
        }

        setAnimationState('exiting');

        setTimeout(() => {
            setCurrentIndex((prev) => prev - 1);
            setAnimationState('entering');

            setTimeout(() => {
                setAnimationState('idle');
            }, 300);
        }, 300);
    }, [animationState, currentIndex]);

    return {
        isLoading,
        isComplete,
        uncategorizedTransactions,
        currentTransaction,
        currentIndex,
        remainingCount,
        animationState,
        lastSelectedCategory,
        sortedCategories,
        searchValue,
        setSearchValue,
        rulesDialogOpen,
        setRulesDialogOpen,
        automateDialogOpen,
        automateCandidate,
        categorizedCount,
        handleCategorySelect,
        handleSkip,
        handlePrevious,
        handleRulesDialogClose,
        handleAutomateDialogOpenChange,
        handleAutomateSaved,
        commandInputRef,
    };
}

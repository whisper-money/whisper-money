import type { AutomateCategorizationCandidate } from '@/components/automation-rules/automate-categorization-dialog';
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

/**
 * The onboarding step files movements one after another, so its confirmations
 * are given one id: a new one replaces the last instead of stacking five of
 * them. They are also short-lived and dismissed when the step goes — at twelve
 * seconds they used to follow the user onto the close screen and the paywall.
 */
const ONBOARDING_TOAST_ID = 'onboarding-transaction-categorized';
const ONBOARDING_TOAST_MS = 4000;

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
    /**
     * Which screen is running the flow. The hook is shared between the
     * onboarding step and the transactions page, and without this every event
     * it captures reads as the same surface.
     */
    source?: 'categorize_flow' | 'onboarding';
    /**
     * Send a skipped movement to the back of the queue rather than past the end
     * of it. The onboarding step gates on a real count of categorized
     * movements, so a queue that empties by skipping would leave the user
     * looking at nothing with the gate still shut.
     */
    recycleSkipped?: boolean;
}

export function useCategorizeTransactions({
    categories,
    accounts,
    banks,
    transactions: initialTransactions,
    source = 'categorize_flow',
    recycleSkipped = false,
}: UseCategorizeTransactionsOptions) {
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
    const [categorizedCount, setCategorizedCount] = useState(0);
    const commandInputRef = useRef<HTMLInputElement>(null);

    const isOnboarding = source === 'onboarding';

    useEffect(() => {
        setCategoryUsageOrder(getCategoryUsageOrder());
    }, []);

    useEffect(() => {
        if (!isOnboarding) {
            return;
        }

        return () => toast.dismiss(ONBOARDING_TOAST_ID);
    }, [isOnboarding]);

    useEffect(() => {
        if (!isLoading && animationState === 'idle') {
            commandInputRef.current?.focus();
        }
    }, [isLoading, animationState, currentIndex]);

    useEffect(() => {
        setIsLoading(true);
        try {
            const accountsMap = new Map(
                accounts.map((account) => [account.id, account]),
            );
            const banksMap = new Map(banks.map((bank) => [bank.id, bank]));

            const processed = initialTransactions.map((transaction) => {
                const account = accountsMap.get(transaction.account_id);
                const bank = account?.bank?.id
                    ? banksMap.get(account.bank.id)
                    : undefined;

                return {
                    ...transaction,
                    decryptedDescription: transaction.description,
                    decryptedNotes: transaction.notes || null,
                    account,
                    category: null,
                    bank,
                } as DecryptedTransaction;
            });

            processed.sort((a, b) => {
                const dateA = parseISO(a.transaction_date).getTime();
                const dateB = parseISO(b.transaction_date).getTime();
                return dateB - dateA;
            });

            setUncategorizedTransactions(processed);
        } catch (error) {
            console.error('Failed to load uncategorized transactions:', error);
        } finally {
            setIsLoading(false);
        }
    }, [initialTransactions, accounts, banks]);

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
                    id: isOnboarding ? ONBOARDING_TOAST_ID : undefined,
                    closeButton: true,
                    duration: isOnboarding ? ONBOARDING_TOAST_MS : 12000,
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
        [currentTransaction, animationState, source, isOnboarding],
    );

    const handleSkip = useCallback(() => {
        if (animationState !== 'idle') {
            return;
        }

        setAnimationState('exiting');

        setTimeout(() => {
            if (recycleSkipped) {
                setUncategorizedTransactions((prev) => {
                    const next = [...prev];
                    const [skipped] = next.splice(currentIndex, 1);
                    if (skipped) {
                        next.push(skipped);
                    }
                    return next;
                });
            } else {
                setCurrentIndex((prev) => prev + 1);
            }
            setAnimationState('entering');

            setTimeout(() => {
                setAnimationState('idle');
            }, 300);
        }, 300);
    }, [animationState, currentIndex, recycleSkipped]);

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

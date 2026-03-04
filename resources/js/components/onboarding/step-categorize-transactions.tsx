import { AutomationRulesDialog } from '@/components/automation-rules/automation-rules-dialog';
import { StepButton } from '@/components/onboarding/step-button';
import { CategorizerCard } from '@/components/transactions/categorizer-card';
import { CategorizerCommand } from '@/components/transactions/categorizer-command';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { Skeleton } from '@/components/ui/skeleton';
import { useCategorizeTransactions } from '@/hooks/use-categorize-transactions';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { CheckCircle2, Settings2, SkipForward } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';

interface StepCategorizeTransactionsProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    transactions: Transaction[];
    onComplete: () => void;
}

export function StepCategorizeTransactions({
    categories,
    accounts,
    banks,
    transactions,
    onComplete,
}: StepCategorizeTransactionsProps) {
    const [showRulesHint, setShowRulesHint] = useState(false);
    const [hasSeenHint, setHasSeenHint] = useState(false);

    const {
        isLoading,
        isComplete,
        uncategorizedTransactions,
        currentTransaction,
        remainingCount,
        animationState,
        lastSelectedCategory,
        sortedCategories,
        searchValue,
        setSearchValue,
        rulesDialogOpen,
        setRulesDialogOpen,
        categorizedCount,
        handleCategorySelect,
        handleSkip,
        handleRulesDialogClose,
        commandInputRef,
    } = useCategorizeTransactions({
        categories,
        accounts,
        banks,
        transactions,
    });

    const totalAvailable = uncategorizedTransactions.length;
    const minimumRequired = Math.min(5, totalAvailable);
    const canContinue =
        isComplete ||
        categorizedCount >= minimumRequired ||
        totalAvailable === 0;

    // Show rules hint after first categorization, only once
    useEffect(() => {
        if (categorizedCount === 1 && !hasSeenHint) {
            setShowRulesHint(true);
        }
    }, [categorizedCount, hasSeenHint]);

    const dismissRulesHint = useCallback(() => {
        setShowRulesHint(false);
        setHasSeenHint(true);
    }, []);

    const handleRulesButtonClick = useCallback(() => {
        dismissRulesHint();
        setRulesDialogOpen(true);
    }, [dismissRulesHint, setRulesDialogOpen]);

    const handleRulesDialogCloseWithHint = useCallback(
        async (open: boolean) => {
            await handleRulesDialogClose(open);
        },
        [handleRulesDialogClose],
    );

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                handleRulesButtonClick();
            }
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                if (
                    animationState === 'idle' &&
                    currentTransaction &&
                    !showRulesHint
                ) {
                    handleSkip();
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [
        animationState,
        currentTransaction,
        handleSkip,
        handleRulesButtonClick,
        showRulesHint,
    ]);

    if (isLoading) {
        return (
            <div className="flex w-full flex-col items-center gap-6">
                <Skeleton className="mx-auto h-6 w-32" />
                <Skeleton className="h-48 w-full rounded-2xl" />
                <div className="grid w-full grid-cols-3 gap-3">
                    {Array.from({ length: 6 }).map((_, i) => (
                        <Skeleton key={i} className="h-12 rounded-xl" />
                    ))}
                </div>
            </div>
        );
    }

    if (totalAvailable === 0) {
        return (
            <div className="flex w-full flex-col items-center gap-6 text-center">
                <div className="flex h-16 w-16 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                    <CheckCircle2 className="h-8 w-8 text-emerald-600 dark:text-emerald-400" />
                </div>
                <div className="space-y-2">
                    <h2 className="text-xl font-semibold">
                        {__('No Uncategorized Transactions')}
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'All your transactions are already categorized. You are all set!',
                        )}
                    </p>
                </div>
                <StepButton text={__('Continue')} onClick={onComplete} />
            </div>
        );
    }

    return (
        <div className="flex w-full flex-col gap-4">
            {/* Header row */}
            <div className="flex items-center justify-between">
                <Popover open={showRulesHint} onOpenChange={() => {}}>
                    <PopoverTrigger asChild>
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={handleRulesButtonClick}
                            className="gap-2 pr-2"
                        >
                            <Settings2 className="h-4 w-4" />
                            {__('Rules')}
                            <Kbd>{__('Ctrl+R')}</Kbd>
                        </Button>
                    </PopoverTrigger>
                    <PopoverContent
                        side="bottom"
                        align="start"
                        className="w-80"
                        onInteractOutside={(e) => e.preventDefault()}
                    >
                        <div className="flex flex-col gap-3">
                            <p className="text-sm">
                                {__(
                                    'If a transaction repeats with a certain frequency or is recurring, you can create an automatic rule for it by clicking this button.',
                                )}
                            </p>
                            <Button
                                size="sm"
                                variant="secondary"
                                onClick={dismissRulesHint}
                                className="self-end"
                            >
                                {__('Got it')}
                            </Button>
                        </div>
                    </PopoverContent>
                </Popover>

                <div className="flex items-center gap-3">
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={handleSkip}
                        disabled={
                            animationState !== 'idle' ||
                            !currentTransaction ||
                            showRulesHint
                        }
                        className="gap-2 pr-2 text-muted-foreground"
                    >
                        <SkipForward className="h-4 w-4" />
                        {__('Skip')}
                        <Kbd>{__('Ctrl+N')}</Kbd>
                    </Button>

                    <span className="text-sm text-muted-foreground">
                        <span className="font-medium text-foreground">
                            {remainingCount}
                        </span>{' '}
                        {__('remaining')}
                    </span>
                </div>
            </div>

            {/* Categorizer card */}
            <CategorizerCard
                transaction={currentTransaction}
                animationState={animationState}
                lastSelectedCategory={lastSelectedCategory}
            />

            {/* Category command palette */}
            <CategorizerCommand
                sortedCategories={sortedCategories}
                animationState={animationState}
                currentTransaction={currentTransaction}
                searchValue={searchValue}
                onSearchChange={setSearchValue}
                onCategorySelect={handleCategorySelect}
                commandInputRef={commandInputRef}
                disabled={showRulesHint}
            />

            {/* Progress + Continue */}
            <div className="flex flex-col items-center gap-3 pt-2">
                {!isComplete && minimumRequired > 0 && (
                    <p className="text-sm text-muted-foreground">
                        {categorizedCount} / {minimumRequired}{' '}
                        {__('categorized')}
                    </p>
                )}
                <StepButton
                    text={__('Continue')}
                    onClick={onComplete}
                    disabled={!canContinue}
                />
            </div>

            <AutomationRulesDialog
                open={rulesDialogOpen}
                onOpenChange={handleRulesDialogCloseWithHint}
            />
        </div>
    );
}

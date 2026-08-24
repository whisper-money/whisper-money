import { AutomationRulesDialog } from '@/components/automation-rules/automation-rules-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
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
import {
    ChartPie,
    Check,
    Settings2,
    SkipForward,
    Target,
    TrendingDown,
    Zap,
} from 'lucide-react';
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
    const [hasStarted, setHasStarted] = useState(false);
    const [showRulesHint, setShowRulesHint] = useState(false);
    const [hasSeenHint, setHasSeenHint] = useState(false);

    const {
        isLoading,
        isComplete,
        uncategorizedTransactions,
        currentTransaction,
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
            <StepScreen width="xl">
                <div className="flex flex-col gap-5">
                    <Skeleton className="h-6 w-40" />
                    <Skeleton className="h-40 w-full rounded-lg" />
                    <Skeleton className="h-13 w-full rounded-lg" />
                    <div className="flex flex-col gap-3">
                        {Array.from({ length: 5 }).map((_, i) => (
                            <Skeleton key={i} className="h-10 rounded-md" />
                        ))}
                    </div>
                </div>
            </StepScreen>
        );
    }

    if (totalAvailable === 0) {
        return (
            <StepScreen
                align="center"
                title={__('No Uncategorized Transactions')}
                description={__(
                    'All your transactions are already categorized. You are all set!',
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={onComplete} />
                }
            />
        );
    }

    if (!hasStarted) {
        return (
            <StepScreen
                title={__('Categorize Your Transactions')}
                description={__(
                    'To continue, you need to categorize at least :count transactions.',
                    { count: minimumRequired },
                )}
                footer={
                    <StepButton
                        text={__("Let's start")}
                        onClick={() => setHasStarted(true)}
                    />
                }
            >
                <StepList>
                    <StepRow
                        icon={ChartPie}
                        title={__('See where you spend')}
                        description={__(
                            'Get a clear picture of where your money goes every month.',
                        )}
                    />
                    <StepRow
                        icon={Target}
                        title={__('Build better budgets')}
                        description={__(
                            'Create realistic budgets based on your actual spending habits.',
                        )}
                    />
                    <StepRow
                        icon={TrendingDown}
                        title={__('Spot savings opportunities')}
                        description={__(
                            'Identify categories where you can cut back and save more.',
                        )}
                    />
                    <StepRow
                        icon={Zap}
                        title={__('Automate over time')}
                        description={__(
                            'Rules will categorize future transactions for you automatically.',
                        )}
                    />
                </StepList>
            </StepScreen>
        );
    }

    return (
        <StepScreen
            width="xl"
            footer={
                <div className="grid grid-cols-2 gap-2.5">
                    <StepButton
                        text={__('Skip')}
                        variant="outline"
                        icon={SkipForward}
                        trailing={<Kbd>{__('Ctrl+N')}</Kbd>}
                        onClick={handleSkip}
                        disabled={
                            animationState !== 'idle' ||
                            !currentTransaction ||
                            showRulesHint
                        }
                    />
                    <StepButton
                        text={__('Continue')}
                        onClick={onComplete}
                        disabled={!canContinue}
                    />
                </div>
            }
        >
            <h1 className="sr-only">{__('Categorize Your Transactions')}</h1>

            <div className="flex flex-col gap-5">
                <div className="flex justify-end">
                    <Popover open={showRulesHint} onOpenChange={() => {}}>
                        <PopoverTrigger asChild>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={handleRulesButtonClick}
                                className="gap-2 pr-2"
                            >
                                <Settings2 className="size-4" />
                                {__('Rules')}
                                <Kbd>{__('Ctrl+R')}</Kbd>
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent
                            side="bottom"
                            align="end"
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
                </div>

                {/* Progress: makes the "categorize at least N" goal obvious */}
                <div className="flex flex-col gap-2.5 rounded-lg border p-4">
                    {canContinue ? (
                        <div className="flex items-center gap-3">
                            <Check className="size-5 shrink-0" />
                            <p className="text-[15px] font-medium">
                                {__(
                                    'Done! You can continue now, or keep categorizing if you want.',
                                )}
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="flex items-baseline justify-between gap-3">
                                <p className="text-[15px] font-medium">
                                    {__(
                                        'To continue, you need to categorize at least :count transactions.',
                                        { count: minimumRequired },
                                    )}
                                </p>
                                <span className="shrink-0 text-sm font-semibold tabular-nums">
                                    {categorizedCount}/{minimumRequired}
                                </span>
                            </div>
                            <div className="flex gap-1.5">
                                {Array.from({ length: minimumRequired }).map(
                                    (_, i) => (
                                        <div
                                            key={i}
                                            className={`h-1 flex-1 rounded-full transition-colors ${
                                                i < categorizedCount
                                                    ? 'bg-foreground'
                                                    : 'bg-border'
                                            }`}
                                        />
                                    ),
                                )}
                            </div>
                            <p className="text-[13px] text-muted-foreground">
                                {__(
                                    'You do not need to categorize all of them.',
                                )}
                            </p>
                        </>
                    )}
                </div>

                <CategorizerCard
                    transaction={currentTransaction}
                    animationState={animationState}
                    lastSelectedCategory={lastSelectedCategory}
                />

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
            </div>

            <AutomationRulesDialog
                open={rulesDialogOpen}
                onOpenChange={handleRulesDialogCloseWithHint}
            />
            <PostSaveApplyRulePrompt />
        </StepScreen>
    );
}

import { AutomationRulesDialog } from '@/components/automation-rules/automation-rules-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { StepButton } from '@/components/onboarding/step-button';
import { StepNote, StepScreen } from '@/components/onboarding/step-screen';
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
import { Check, Settings2, SkipForward } from 'lucide-react';
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
        source: 'onboarding',
        // Skipping brings another movement round instead of running the queue
        // out: the gate below counts categorized movements, so a queue that
        // empties by skipping would leave the step with nothing on screen and
        // no way forward.
        recycleSkipped: true,
    });

    const totalAvailable = uncategorizedTransactions.length;
    const minimumRequired = Math.min(5, totalAvailable);
    // Reaching the end of the queue is not a way through: skipping every
    // movement used to count as finishing the step, which let a user leave with
    // nothing categorized at all. The minimum is min(5, total), so it lowers
    // itself when there is little to do and traps nobody.
    const canContinue =
        categorizedCount >= minimumRequired ||
        totalAvailable === 0 ||
        // Nothing to file them under is not the user's fault, and the gate
        // could never be met.
        categories.length === 0;

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
                title={__('Nothing left to teach us')}
                description={__(
                    'Every movement you brought already has a category. The rules did their job.',
                )}
                footer={
                    <StepButton text={__('Continue')} onClick={onComplete} />
                }
            />
        );
    }

    return (
        <StepScreen
            width="xl"
            title={__('Teach us your habits')}
            description={__(
                'The rules couldn’t place these on their own. File :count of them and we carry on — each one you file is one we never ask about again.',
                { count: minimumRequired },
            )}
            footer={
                <>
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
                    <StepNote>
                        {__(
                            'Not sure about one? Skip it and we’ll bring you another.',
                        )}
                    </StepNote>
                </>
            }
        >
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
                                    'That’s enough to continue. Keep going if you’re enjoying it.',
                                )}
                            </p>
                        </div>
                    ) : (
                        <>
                            <div className="flex items-baseline justify-between gap-3">
                                <p className="text-[15px] font-medium">
                                    {__('File :count movements to carry on', {
                                        count: minimumRequired,
                                    })}
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
                                    'The rest can wait — they’ll be on your transactions screen.',
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

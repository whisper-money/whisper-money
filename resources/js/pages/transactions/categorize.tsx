import { categorize as categorizeRoute } from '@/actions/App/Http/Controllers/TransactionController';
import { AutomateCategorizationDialog } from '@/components/automation-rules/automate-categorization-dialog';
import { AutomationRulesDialog } from '@/components/automation-rules/automation-rules-dialog';
import { PostSaveApplyRulePrompt } from '@/components/automation-rules/post-save-apply-rule-prompt';
import { CategorizerCard } from '@/components/transactions/categorizer-card';
import { CategorizerCommand } from '@/components/transactions/categorizer-command';
import { Button } from '@/components/ui/button';
import { Kbd } from '@/components/ui/kbd';
import { Skeleton } from '@/components/ui/skeleton';
import { useCategorizeTransactions } from '@/hooks/use-categorize-transactions';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Transaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowLeft,
    CheckCircle2,
    PartyPopper,
    Settings2,
    SkipBack,
    SkipForward,
} from 'lucide-react';
import { useEffect } from 'react';

interface Props {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    transactions: Transaction[];
}

export default function CategorizeTransactions({
    categories,
    accounts,
    banks,
    transactions,
}: Props) {
    const { trigger } = useWebHaptics();

    const {
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
        handleCategorySelect,
        handleSkip,
        handlePrevious,
        handleRulesDialogClose,
        handleAutomateDialogOpenChange,
        handleAutomateSaved,
        commandInputRef,
    } = useCategorizeTransactions({
        categories,
        accounts,
        banks,
        transactions,
    });

    const backHref = categorizeRoute.url()?.replace('/categorize', '') ?? '';
    const automateDialog = (
        <>
            <AutomateCategorizationDialog
                open={automateDialogOpen}
                candidate={automateCandidate}
                categories={categories}
                onOpenChange={handleAutomateDialogOpenChange}
                onSaved={handleAutomateSaved}
            />
            <PostSaveApplyRulePrompt />
        </>
    );

    useEffect(() => {
        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.ctrlKey && e.key === 'r') {
                e.preventDefault();
                setRulesDialogOpen(true);
            }
            if (e.ctrlKey && e.key === 'n') {
                e.preventDefault();
                if (animationState === 'idle' && currentTransaction) {
                    handleSkip();
                }
            }
            if (e.ctrlKey && e.key === 'b') {
                e.preventDefault();
                if (animationState === 'idle' && currentIndex > 0) {
                    handlePrevious();
                }
            }
            if (e.key === 'Escape' && !rulesDialogOpen && !automateDialogOpen) {
                e.preventDefault();
                if (backHref) {
                    router.visit(backHref);
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
        automateDialogOpen,
        setRulesDialogOpen,
        backHref,
    ]);

    if (isLoading) {
        return (
            <>
                <Head title={__('Categorize Transactions')} />
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
                <Head title={__('All Done!')} />
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
                                {__('All Done!')}
                            </h1>
                            <p className="text-lg text-zinc-600 dark:text-zinc-400">
                                {__(
                                    "You've categorized all your transactions.",
                                )}
                            </p>
                        </div>
                        <Link href={backHref} onClick={() => trigger('light')}>
                            <Button size="lg" className="mt-4">
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                {__('Back to Transactions')}
                            </Button>
                        </Link>
                    </div>
                </div>

                {automateDialog}

                <style>{`
                    @keyframes bounce-in {
                        0% { opacity: 0; transform: scale(0.3); }
                        50% { transform: scale(1.05); }
                        70% { transform: scale(0.9); }
                        100% { opacity: 1; transform: scale(1); }
                    }
                    .animate-bounce-in {
                        animation: bounce-in 0.6s ease-out forwards;
                    }
                `}</style>
            </>
        );
    }

    if (uncategorizedTransactions.length === 0) {
        return (
            <>
                <Head title={__('Categorize Transactions')} />
                <div className="flex min-h-screen flex-col items-center justify-center bg-white p-4 dark:bg-zinc-950">
                    <div className="flex flex-col items-center gap-6 text-center">
                        <div className="flex h-20 w-20 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                            <CheckCircle2 className="h-10 w-10 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div className="space-y-2">
                            <h1 className="text-2xl font-bold text-zinc-900 dark:text-zinc-100">
                                {__('No Uncategorized Transactions')}
                            </h1>
                            <p className="text-zinc-600 dark:text-zinc-400">
                                {__(
                                    'All your transactions are already categorized.',
                                )}
                            </p>
                        </div>
                        <Link href={backHref} onClick={() => trigger('light')}>
                            <Button>
                                <ArrowLeft className="mr-2 h-4 w-4" />
                                {__('Back to Transactions')}
                            </Button>
                        </Link>
                    </div>
                </div>
                {automateDialog}
            </>
        );
    }

    return (
        <>
            <Head title={__('Categorize Transactions')} />

            <div className="flex min-h-screen flex-col bg-white dark:bg-zinc-950">
                <header className="flex items-center justify-between gap-6 px-4 py-3 dark:border-zinc-800">
                    <Link
                        href={backHref}
                        onClick={() => trigger('light')}
                        className="flex w-fit flex-1 items-center gap-2 text-sm text-zinc-600 opacity-50 transition-all duration-200 hover:text-zinc-900 hover:opacity-100 dark:text-zinc-400 dark:hover:text-zinc-100"
                    >
                        <ArrowLeft className="h-4 w-4" />
                        <div className="hidden text-nowrap sm:block">
                            {__('Back to Transactions')}
                        </div>
                    </Link>
                    <div className="flex w-full items-center justify-end gap-6 sm:justify-center">
                        <div className="flex items-center gap-3">
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => setRulesDialogOpen(true)}
                                className="gap-2 pr-2"
                            >
                                <Settings2 className="h-4 w-4" />
                                {__('Rules')}
                                <Kbd>{__('Ctrl+R')}</Kbd>
                            </Button>
                        </div>
                        <div className="flex items-center gap-3">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={handlePrevious}
                                disabled={
                                    animationState !== 'idle' ||
                                    currentIndex === 0
                                }
                                className="gap-2 pr-2 text-muted-foreground"
                            >
                                <SkipBack className="h-4 w-4" />
                                <span className="">{__('Prev')}</span>
                                <Kbd>{__('Ctrl+B')}</Kbd>
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={handleSkip}
                                disabled={
                                    animationState !== 'idle' ||
                                    !currentTransaction
                                }
                                className="gap-2 pr-2 text-muted-foreground"
                            >
                                <SkipForward className="h-4 w-4" />
                                <span className="">{__('Skip')}</span>
                                <Kbd>{__('Ctrl+N')}</Kbd>
                            </Button>
                        </div>
                    </div>
                    <div className="hidden flex-1 items-center gap-2 text-sm text-zinc-600 sm:flex dark:text-zinc-400">
                        <span className="font-medium text-zinc-900 dark:text-zinc-100">
                            {remainingCount}
                        </span>
                        remaining
                    </div>
                </header>

                <main className="flex flex-1 flex-col items-center justify-start p-4 sm:justify-center">
                    <div className="w-full max-w-xl space-y-8">
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
                        />
                    </div>
                </main>
            </div>

            <AutomationRulesDialog
                open={rulesDialogOpen}
                onOpenChange={handleRulesDialogClose}
            />
            {automateDialog}
        </>
    );
}

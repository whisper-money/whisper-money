import { categorize } from '@/actions/App/Http/Controllers/TransactionController';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useIsMobile } from '@/hooks/use-mobile';
import { ICON_ONLY_BELOW_SM } from '@/lib/icon-only-below-sm';
import { hasActiveFilters } from '@/lib/transaction-filter-serialization';
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';
import { cn } from '@/lib/utils';

import {
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { BarChart3, ChevronDown, Tags, WandSparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AddTransactionButton } from './add-transaction-button';
import { TransactionAnalysisDrawer } from './transaction-analysis-drawer';

interface TransactionActionsMenuProps {
    transactions: ServerTransaction[];
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    onReEvaluateComplete?: () => void;
    /**
     * Offer the analysis of the filtered rows. Off on /transactions, where the
     * summary of a filtered list opens it instead.
     */
    showAnalysis?: boolean;
    /** What the analysis reports on; needed with `showAnalysis`. */
    filters?: TransactionFilters;
}

/**
 * The bar above the transactions table. Adding a transaction sits here, right
 * above the list it lands in; importing lives in the app header, so it is not
 * repeated here.
 */
export function TransactionActionsMenu({
    transactions,
    hasTransactionalAccounts,
    onReEvaluateComplete,
    showAnalysis = false,
    filters,
}: TransactionActionsMenuProps) {
    const isMobile = useIsMobile();
    const [analysisDrawerOpen, setAnalysisDrawerOpen] = useState(false);
    const [analysisHintOpen, setAnalysisHintOpen] = useState(false);
    const { reEvaluate, isReEvaluating } =
        useReEvaluateRules(onReEvaluateComplete);

    const canAnalyze = filters !== undefined && hasActiveFilters(filters);

    // A hint opened while the button was disabled must not outlive that
    // state, or applying a filter would leave a stale tooltip on screen.
    const [hintCanAnalyze, setHintCanAnalyze] = useState(canAnalyze);
    if (hintCanAnalyze !== canAnalyze) {
        setHintCanAnalyze(canAnalyze);
        setAnalysisHintOpen(false);
    }

    const handleAnalysisClick = () => {
        if (!canAnalyze) {
            if (isMobile) {
                setAnalysisHintOpen((open) => !open);
            }
            return;
        }
        setAnalysisDrawerOpen(true);
    };

    return (
        <div className="flex items-center gap-2">
            <AddTransactionButton
                hasTransactionalAccounts={hasTransactionalAccounts}
            />

            <ButtonGroup>
                {showAnalysis && (
                    <TooltipProvider>
                        <Tooltip
                            open={analysisHintOpen}
                            onOpenChange={setAnalysisHintOpen}
                        >
                            <TooltipTrigger asChild>
                                <Button
                                    variant="outline"
                                    className={cn(
                                        ICON_ONLY_BELOW_SM.button,
                                        !canAnalyze &&
                                            'cursor-not-allowed opacity-50',
                                    )}
                                    aria-disabled={!canAnalyze}
                                    aria-label={__('Analysis')}
                                    onClick={handleAnalysisClick}
                                >
                                    <BarChart3 className="h-5 w-5" />
                                    <span className={ICON_ONLY_BELOW_SM.label}>
                                        {__('Analysis')}
                                    </span>
                                </Button>
                            </TooltipTrigger>
                            {/* Enabled, it only names the icon-only button. */}
                            <TooltipContent
                                className={canAnalyze ? 'sm:hidden' : undefined}
                            >
                                {canAnalyze
                                    ? __('Analysis')
                                    : __(
                                          'Apply a filter to enable this button',
                                      )}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                )}

                {!isMobile && <CategorizeButton transactions={transactions} />}

                <DropdownMenu>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon"
                                        aria-label={__('More actions')}
                                    >
                                        <ChevronDown className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                            </TooltipTrigger>
                            <TooltipContent>
                                {__('More actions')}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <DropdownMenuContent align="end">
                        {isMobile && (
                            <CategorizeMenuItem transactions={transactions} />
                        )}
                        <DropdownMenuItem
                            onClick={reEvaluate}
                            disabled={isReEvaluating}
                        >
                            <WandSparkles className="mr-2 h-4 w-4" />
                            {__('Update categories automatically')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </ButtonGroup>

            {showAnalysis && filters && (
                <TransactionAnalysisDrawer
                    open={analysisDrawerOpen}
                    onOpenChange={setAnalysisDrawerOpen}
                    filters={filters}
                />
            )}
        </div>
    );
}

function countUncategorized(transactions: ServerTransaction[]): number {
    return transactions.filter((transaction) => !transaction.category_id)
        .length;
}

function CategorizeLabel({ count }: { count: number }) {
    return (
        <>
            {__('Categorize')}
            {count > 0 && (
                <span className="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                    {count}
                </span>
            )}
        </>
    );
}

/** "Categorize" in a "⋯" menu, where the page has no room for its button. */
export function CategorizeMenuItem({
    transactions,
}: {
    transactions: ServerTransaction[];
}) {
    const count = countUncategorized(transactions);

    return (
        <DropdownMenuItem disabled={count === 0} asChild={count > 0}>
            {withCategorizeLink(
                count,
                <>
                    <Tags className="mr-2 h-4 w-4" />
                    <CategorizeLabel count={count} />
                </>,
            )}
        </DropdownMenuItem>
    );
}

function withCategorizeLink(count: number, content: ReactNode): ReactNode {
    return count > 0 ? <Link href={categorize.url()}>{content}</Link> : content;
}

/**
 * "Categorize" with how many of the listed transactions have no category,
 * linking to the categorize page; disabled when there are none.
 */
export function CategorizeButton({
    transactions,
}: {
    transactions: ServerTransaction[];
}) {
    const count = countUncategorized(transactions);
    const hasUncategorized = count > 0;

    return (
        <TooltipProvider>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        variant="outline"
                        className={
                            hasUncategorized
                                ? ''
                                : 'cursor-not-allowed opacity-50'
                        }
                        disabled={!hasUncategorized}
                        asChild={hasUncategorized}
                    >
                        {withCategorizeLink(
                            count,
                            <CategorizeLabel count={count} />,
                        )}
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    {hasUncategorized
                        ? `Categorize ${count} transactions`
                        : 'All transactions are categorized'}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}

/** Runs every rule over every transaction again, "Update categories automatically". */
export function useReEvaluateRules(onComplete?: () => void): {
    reEvaluate: () => Promise<void>;
    isReEvaluating: boolean;
} {
    const [isReEvaluating, setIsReEvaluating] = useState(false);

    async function reEvaluate() {
        setIsReEvaluating(true);
        try {
            if ((await reEvaluateTransactionRules()) === 'done') {
                onComplete?.();
            }
        } finally {
            setIsReEvaluating(false);
        }
    }

    return { reEvaluate, isReEvaluating };
}

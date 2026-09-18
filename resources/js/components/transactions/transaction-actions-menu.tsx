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
import { useReEvaluateAllTransactions } from '@/hooks/use-re-evaluate-all-transactions';
import { hasActiveFilters } from '@/lib/transaction-filter-serialization';

import {
    type ServerTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { BarChart3, ChevronDown, Tags, WandSparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { TransactionAnalysisDrawer } from './transaction-analysis-drawer';

interface TransactionActionsMenuProps {
    transactions: ServerTransaction[];
    onReEvaluateComplete?: () => void;
    filters: TransactionFilters;
}

/**
 * The bar above the transactions table. Creating and importing live in the
 * app header, so neither is repeated here.
 */
export function TransactionActionsMenu({
    transactions,
    onReEvaluateComplete,
    filters,
}: TransactionActionsMenuProps) {
    const isMobile = useIsMobile();
    const [analysisDrawerOpen, setAnalysisDrawerOpen] = useState(false);
    const [analysisHintOpen, setAnalysisHintOpen] = useState(false);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const { reEvaluateAll } = useReEvaluateAllTransactions();

    const canAnalyze = hasActiveFilters(filters);

    const handleAnalysisClick = () => {
        if (!canAnalyze) {
            if (isMobile) {
                setAnalysisHintOpen((open) => !open);
            }
            return;
        }
        setAnalysisDrawerOpen(true);
    };

    const handleReEvaluateAll = async () => {
        setIsReEvaluating(true);
        try {
            await reEvaluateAll();
            onReEvaluateComplete?.();
        } finally {
            setIsReEvaluating(false);
        }
    };

    const uncategorizedCount = transactions.filter(
        (t) => !t.category_id,
    ).length;

    const hasUncategorized = uncategorizedCount > 0;

    const categorizeLabel = (
        <>
            {__('Categorize')}
            {hasUncategorized && (
                <span className="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                    {uncategorizedCount}
                </span>
            )}
        </>
    );

    const withCategorizeLink = (content: ReactNode) =>
        hasUncategorized ? (
            <Link href={categorize.url()}>{content}</Link>
        ) : (
            content
        );

    return (
        <>
            <ButtonGroup>
                <TooltipProvider>
                    <Tooltip
                        open={!canAnalyze && analysisHintOpen}
                        onOpenChange={setAnalysisHintOpen}
                    >
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                className={
                                    !canAnalyze
                                        ? 'cursor-not-allowed opacity-50'
                                        : ''
                                }
                                aria-disabled={!canAnalyze}
                                onClick={handleAnalysisClick}
                            >
                                <BarChart3 className="h-5 w-5" />
                                {__('Analysis')}
                            </Button>
                        </TooltipTrigger>
                        {!canAnalyze && (
                            <TooltipContent>
                                {__('Apply a filter to enable this button')}
                            </TooltipContent>
                        )}
                    </Tooltip>
                </TooltipProvider>

                {!isMobile && (
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
                                    {withCategorizeLink(categorizeLabel)}
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {hasUncategorized
                                    ? `Categorize ${uncategorizedCount} transactions`
                                    : 'All transactions are categorized'}
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                )}

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
                            <DropdownMenuItem
                                disabled={!hasUncategorized}
                                asChild={hasUncategorized}
                            >
                                {withCategorizeLink(
                                    <>
                                        <Tags className="mr-2 h-4 w-4" />
                                        {categorizeLabel}
                                    </>,
                                )}
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onClick={handleReEvaluateAll}
                            disabled={isReEvaluating}
                        >
                            <WandSparkles className="mr-2 h-4 w-4" />
                            {__('Update categories automatically')}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </ButtonGroup>

            <TransactionAnalysisDrawer
                open={analysisDrawerOpen}
                onOpenChange={setAnalysisDrawerOpen}
                filters={filters}
            />
        </>
    );
}

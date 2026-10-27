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
import { reEvaluateTransactionRules } from '@/lib/transaction-re-evaluation';

import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ChevronDown, Tags, WandSparkles } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { AddTransactionButton } from './add-transaction-button';

interface TransactionActionsMenuProps {
    transactions: ServerTransaction[];
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    onReEvaluateComplete?: () => void;
}

/**
 * The bar above the transactions table. Adding a transaction sits here, right
 * above the list it lands in; importing lives in the app header, so it is not
 * repeated here, and the analysis opens from the summary of a filtered list.
 */
export function TransactionActionsMenu({
    transactions,
    hasTransactionalAccounts,
    onReEvaluateComplete,
}: TransactionActionsMenuProps) {
    const isMobile = useIsMobile();
    const [isReEvaluating, setIsReEvaluating] = useState(false);

    const handleReEvaluateAll = async () => {
        setIsReEvaluating(true);
        try {
            if ((await reEvaluateTransactionRules()) === 'done') {
                onReEvaluateComplete?.();
            }
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
        <div className="flex items-center gap-2">
            <AddTransactionButton
                hasTransactionalAccounts={hasTransactionalAccounts}
            />

            <ButtonGroup>
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
        </div>
    );
}

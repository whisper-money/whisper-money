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
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { useIsMobile } from '@/hooks/use-mobile';
import { useReEvaluateAllTransactions } from '@/hooks/use-re-evaluate-all-transactions';
import { hasActiveFilters } from '@/lib/transaction-filter-serialization';

import { type SharedData } from '@/types';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import {
    type DecryptedTransaction,
    type TransactionFilters,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ChevronDown,
    Plus,
    Upload,
    WandSparkles,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImportTransactionsDrawer } from './import-transactions-drawer';
import { TransactionAnalysisDrawer } from './transaction-analysis-drawer';

interface TransactionActionsMenuProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    automationRules?: AutomationRule[];
    onAddTransaction: () => void;
    transactions: DecryptedTransaction[];
    onReEvaluateComplete?: () => void;
    onImportComplete?: () => void;
    filters: TransactionFilters;
}

export function TransactionActionsMenu({
    categories,
    accounts,
    banks,
    automationRules = [],
    onAddTransaction,
    transactions,
    onReEvaluateComplete,
    onImportComplete,
    filters,
}: TransactionActionsMenuProps) {
    const { isKeySet } = useEncryptionKey();
    const { features } = usePage<SharedData>().props;
    const isMobile = useIsMobile();
    const [importDrawerOpen, setImportDrawerOpen] = useState(false);
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

    const handleAddTransaction = () => {
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to add transactions',
            );
            return;
        }
        onAddTransaction();
    };

    const handleOpenImportDrawer = () => {
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to import transactions',
            );
            return;
        }
        setImportDrawerOpen(true);
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

    return (
        <>
            <ButtonGroup>
                {features.transactionAnalysis &&
                    (isMobile ? (
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
                                        {__(
                                            'Apply a filter to enable this button',
                                        )}
                                    </TooltipContent>
                                )}
                            </Tooltip>
                        </TooltipProvider>
                    ) : (
                        <TooltipProvider>
                            <Tooltip>
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
                                        {__(
                                            'Apply a filter to enable this button',
                                        )}
                                    </TooltipContent>
                                )}
                            </Tooltip>
                        </TooltipProvider>
                    ))}

                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                className={
                                    !isKeySet || uncategorizedCount === 0
                                        ? 'cursor-not-allowed opacity-50'
                                        : ''
                                }
                                disabled={!isKeySet || uncategorizedCount === 0}
                                asChild={isKeySet && uncategorizedCount > 0}
                            >
                                {isKeySet && uncategorizedCount > 0 ? (
                                    <Link href={categorize.url()}>
                                        {__('Categorize')}

                                        {uncategorizedCount > 0 && (
                                            <span className="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                {uncategorizedCount}
                                            </span>
                                        )}
                                    </Link>
                                ) : (
                                    <>{__('Categorize')}</>
                                )}
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {!isKeySet
                                ? 'Unlock encryption to categorize'
                                : uncategorizedCount === 0
                                  ? 'All transactions are categorized'
                                  : `Categorize ${uncategorizedCount} transactions`}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                {!isMobile && (
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button
                                    variant="outline"
                                    className={
                                        !isKeySet
                                            ? 'cursor-not-allowed opacity-50'
                                            : ''
                                    }
                                    onClick={handleAddTransaction}
                                    aria-label={__('Add transaction')}
                                >
                                    <Plus className="h-5 w-5" />
                                    <span className="hidden sm:inline">
                                        {__('Transaction')}
                                    </span>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent>
                                {!isKeySet
                                    ? __(
                                          'Unlock encryption to add transactions',
                                      )
                                    : __('Create a new transaction')}
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
                                onClick={handleAddTransaction}
                                disabled={!isKeySet}
                            >
                                <Plus className="mr-2 h-4 w-4" />
                                {__('Add transaction')}
                            </DropdownMenuItem>
                        )}
                        <DropdownMenuItem
                            onClick={handleOpenImportDrawer}
                            disabled={!isKeySet}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            {__('Import Transactions')}
                        </DropdownMenuItem>
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

            <ImportTransactionsDrawer
                open={importDrawerOpen}
                onOpenChange={setImportDrawerOpen}
                categories={categories}
                accounts={accounts}
                banks={banks}
                automationRules={automationRules}
                onImportComplete={onImportComplete}
            />

            {features.transactionAnalysis && (
                <TransactionAnalysisDrawer
                    open={analysisDrawerOpen}
                    onOpenChange={setAnalysisDrawerOpen}
                    filters={filters}
                />
            )}
        </>
    );
}

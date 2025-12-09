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
import { useReEvaluateAllTransactions } from '@/hooks/use-re-evaluate-all-transactions';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type DecryptedTransaction } from '@/types/transaction';
import { Link } from '@inertiajs/react';
import {
    ChevronDown,
    Plus,
    Sparkles,
    Upload,
    WandSparkles,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

interface TransactionActionsMenuProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
    onAddTransaction: () => void;
    transactions: DecryptedTransaction[];
    onReEvaluateComplete?: () => void;
}

export function TransactionActionsMenu({
    categories,
    accounts,
    banks,
    onAddTransaction,
    transactions,
    onReEvaluateComplete,
}: TransactionActionsMenuProps) {
    const { isKeySet } = useEncryptionKey();
    const [importDrawerOpen, setImportDrawerOpen] = useState(false);
    const [isReEvaluating, setIsReEvaluating] = useState(false);
    const { reEvaluateAll } = useReEvaluateAllTransactions();

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
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to re-evaluate transactions',
            );
            return;
        }

        if (!transactions.length) {
            toast.error('No transactions to re-evaluate');
            return;
        }

        setIsReEvaluating(true);
        try {
            await reEvaluateAll(transactions, categories, accounts, banks);
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
                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size="sm"
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
                                        Categorize
                                        {uncategorizedCount > 0 && (
                                            <span className="ml-1 rounded-full bg-amber-100 px-1.5 py-0.5 text-xs font-medium text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                                {uncategorizedCount}
                                            </span>
                                        )}
                                    </Link>
                                ) : (
                                    <>
                                        Categorize
                                    </>
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

                <TooltipProvider>
                    <Tooltip>
                        <TooltipTrigger asChild>
                            <Button
                                variant="outline"
                                size={'sm'}
                                className={
                                    !isKeySet
                                        ? 'cursor-not-allowed opacity-50'
                                        : ''
                                }
                                onClick={handleAddTransaction}
                                aria-label="Add transaction"
                            >
                                <Plus className="h-5 w-5" />
                                Add Transaction
                            </Button>
                        </TooltipTrigger>
                        <TooltipContent>
                            {!isKeySet
                                ? 'Unlock encryption to add transactions'
                                : 'Create a new transaction'}
                        </TooltipContent>
                    </Tooltip>
                </TooltipProvider>

                <DropdownMenu>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <DropdownMenuTrigger asChild>
                                    <Button
                                        variant="outline"
                                        size="icon-sm"
                                        aria-label="More actions"
                                    >
                                        <ChevronDown className="h-4 w-4" />
                                    </Button>
                                </DropdownMenuTrigger>
                            </TooltipTrigger>
                            <TooltipContent>More actions</TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem
                            onClick={handleOpenImportDrawer}
                            disabled={!isKeySet}
                        >
                            <Upload className="mr-2 h-4 w-4" />
                            Import Transactions
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            onClick={handleReEvaluateAll}
                            disabled={
                                !isKeySet ||
                                isReEvaluating ||
                                !transactions.length
                            }
                        >
                            <WandSparkles className="mr-2 h-4 w-4" />
                            Re-evaluate All Expenses
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
            />
        </>
    );
}

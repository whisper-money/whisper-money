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
import { ChevronDown, Plus, Upload, Zap } from 'lucide-react';
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

    return (
        <>
            <ButtonGroup>
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
                            <Zap className="mr-2 h-4 w-4" />
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

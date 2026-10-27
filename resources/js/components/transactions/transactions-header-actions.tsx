import { AddTransactionButton } from '@/components/transactions/add-transaction-button';
import {
    CategorizeButton,
    useReEvaluateRules,
} from '@/components/transactions/transaction-actions-menu';
import {
    type TransactionExportButtonProps,
    useTransactionExport,
} from '@/components/transactions/transaction-export-button';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type ServerTransaction } from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Download, Ellipsis, WandSparkles } from 'lucide-react';

interface TransactionsHeaderActionsProps {
    transactions: ServerTransaction[];
    /** Whether the user owns an account a manual transaction can be filed in. */
    hasTransactionalAccounts: boolean;
    onReEvaluateComplete: () => void;
    exportOptions: TransactionExportButtonProps;
}

/**
 * The transactions page's actions beside its title on desktop: Categorize,
 * "⋯" for the rest (updating categories by the rules, exporting), and adding
 * a transaction as the main one.
 */
export function TransactionsHeaderActions({
    transactions,
    hasTransactionalAccounts,
    onReEvaluateComplete,
    exportOptions,
}: TransactionsHeaderActionsProps) {
    const { reEvaluate, isReEvaluating } =
        useReEvaluateRules(onReEvaluateComplete);
    const exporter = useTransactionExport(exportOptions);

    return (
        <div
            className="flex items-center gap-2"
            data-testid="transactions-header-actions"
        >
            <CategorizeButton transactions={transactions} />

            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button
                        variant="outline"
                        size="icon"
                        aria-label={__('More actions')}
                    >
                        <Ellipsis className="h-4 w-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem
                        onClick={reEvaluate}
                        disabled={isReEvaluating}
                    >
                        <WandSparkles className="mr-2 h-4 w-4" />
                        {__('Update categories automatically')}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        onSelect={exporter.start}
                        disabled={exporter.disabled}
                    >
                        <Download className="mr-2 h-4 w-4" />
                        {__('Export CSV')}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>

            <AddTransactionButton
                hasTransactionalAccounts={hasTransactionalAccounts}
            />

            {exporter.dialog}
        </div>
    );
}

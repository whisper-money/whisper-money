import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTransactionDialogData } from '@/hooks/use-transaction-dialog-data';
import { refreshPageAfterWrite } from '@/lib/refresh-page';
import { __ } from '@/utils/i18n';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

export function ImportTransactionsButton() {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const { data: importData, loading, load } = useTransactionDialogData();

    const handleOpenDrawer = async () => {
        if (await load()) {
            setDrawerOpen(true);
        }
    };

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            // Icon only at every width: adding a transaction is
                            // the header's labelled action now, and two labels
                            // next to each other read as a pair of equals when
                            // importing is the rarer of the two.
                            className={`h-9 w-9 px-0 has-[>svg]:px-0 ${loading ? 'cursor-not-allowed opacity-50' : ''}`}
                            onClick={handleOpenDrawer}
                            disabled={loading}
                            aria-label={__('Import transactions')}
                        >
                            <Upload className="h-5 w-5" />
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {__('Import transactions from CSV/Excel')}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            {importData && (
                <ImportTransactionsDrawer
                    open={drawerOpen}
                    onOpenChange={setDrawerOpen}
                    // The page underneath was rendered before the import, so
                    // it only learns about the new rows by re-rendering.
                    onImportComplete={refreshPageAfterWrite}
                    accounts={importData.accounts}
                    categories={importData.categories}
                    banks={importData.banks}
                    automationRules={importData.automationRules}
                />
            )}
        </>
    );
}

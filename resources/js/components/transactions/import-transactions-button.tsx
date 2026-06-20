import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

interface ImportData {
    accounts: Account[];
    categories: Category[];
    banks: Bank[];
    automationRules: AutomationRule[];
}

export function ImportTransactionsButton() {
    const [drawerOpen, setDrawerOpen] = useState(false);
    const [importData, setImportData] = useState<ImportData | null>(null);
    const [loading, setLoading] = useState(false);

    const handleOpenDrawer = async () => {
        // Fetch data on-demand when drawer opens
        setLoading(true);
        try {
            const response = await fetch('/api/import/data');
            if (!response.ok) {
                throw new Error('Failed to load import data');
            }
            const data = await response.json();
            setImportData(data);
            setDrawerOpen(true);
        } catch (error) {
            toast.error(__('Failed to load import data'));
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            className={`h-9 ${loading ? 'cursor-not-allowed opacity-50' : ''}`}
                            onClick={handleOpenDrawer}
                            disabled={loading}
                            aria-label={__('Import transactions')}
                        >
                            <Upload className="h-5 w-5" />
                            <span className="">
                                {loading ? __('Loading...') : __('Import')}
                            </span>
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
                    accounts={importData.accounts}
                    categories={importData.categories}
                    banks={importData.banks}
                    automationRules={importData.automationRules}
                />
            )}
        </>
    );
}

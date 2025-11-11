import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Upload } from 'lucide-react';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ImportTransactionsDrawer } from './import-transactions-drawer';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';

interface ImportTransactionsButtonProps {
    categories: Category[];
    accounts: Account[];
    banks: Bank[];
}

export function ImportTransactionsButton({
    categories,
    accounts,
    banks,
}: ImportTransactionsButtonProps) {
    const [drawerOpen, setDrawerOpen] = useState(false);

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            className="h-9"
                            onClick={() => setDrawerOpen(true)}
                            aria-label="Import transactions"
                        >
                            <Upload className="h-5 w-5" />
                            Import
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>Import transactions from CSV/Excel</TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <ImportTransactionsDrawer
                open={drawerOpen}
                onOpenChange={setDrawerOpen}
                categories={categories}
                accounts={accounts}
                banks={banks}
            />
        </>
    );
}


import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useEncryptionKey } from '@/contexts/encryption-key-context';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { ImportTransactionsDrawer } from './import-transactions-drawer';

export function ImportTransactionsButton() {
    const { isKeySet } = useEncryptionKey();
    const [drawerOpen, setDrawerOpen] = useState(false);

    const handleOpenDrawer = () => {
        if (!isKeySet) {
            toast.error(
                'Please unlock your encryption key to import transactions',
            );
            return;
        }
        setDrawerOpen(true);
    };

    return (
        <>
            <TooltipProvider>
                <Tooltip>
                    <TooltipTrigger asChild>
                        <Button
                            variant="ghost"
                            className={`h-9 ${!isKeySet ? 'cursor-not-allowed opacity-50' : ''}`}
                            onClick={handleOpenDrawer}
                            aria-label="Import transactions"
                        >
                            <Upload className="h-5 w-5" />
                            <span className='hidden sm:inline'>Import</span>
                        </Button>
                    </TooltipTrigger>
                    <TooltipContent>
                        {!isKeySet
                            ? `Unlock encryption to import transactions`
                            : `Import transactions from CSV/Excel`}
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>

            <ImportTransactionsDrawer
                open={drawerOpen}
                onOpenChange={setDrawerOpen}
            />
        </>
    );
}

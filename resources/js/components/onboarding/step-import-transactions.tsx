import { ImportTransactionsDrawer } from '@/components/transactions/import-transactions-drawer';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { ArrowRight, FileSpreadsheet, Upload } from 'lucide-react';
import { useState } from 'react';

interface StepImportTransactionsProps {
    account: CreatedAccount | undefined;
    onComplete: () => void;
}

export function StepImportTransactions({
    account,
    onComplete,
}: StepImportTransactionsProps) {
    const [isDrawerOpen, setIsDrawerOpen] = useState(false);
    const [hasImported, setHasImported] = useState(false);

    const handleDrawerClose = (open: boolean) => {
        setIsDrawerOpen(open);
        if (!open) {
            setHasImported(true);
        }
    };

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-indigo-400 to-purple-500 shadow-lg duration-500 zoom-in">
                <Upload className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Import Your Transactions
            </h1>

            <p className="mb-8 max-w-md text-center text-muted-foreground">
                {account
                    ? `Import transactions for "${account.name}". You can export transaction history from your bank's website.`
                    : 'Import your transaction history to start tracking your finances.'}
            </p>

            <div className="mb-8 w-full max-w-md rounded-xl border bg-card p-6">
                <h3 className="mb-4 font-semibold">
                    How to Export from Your Bank:
                </h3>
                <ol className="space-y-3 text-sm text-muted-foreground">
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            1
                        </span>
                        <span>Log in to your bank's website or app</span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            2
                        </span>
                        <span>Go to your account's transaction history</span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            3
                        </span>
                        <span>Look for "Export" or "Download" option</span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            4
                        </span>
                        <span>Download as CSV or Excel format</span>
                    </li>
                </ol>
            </div>

            <div className="mb-6 flex items-center gap-2 rounded-lg border border-dashed border-muted-foreground/30 p-4">
                <FileSpreadsheet className="h-8 w-8 text-muted-foreground" />
                <div>
                    <p className="text-sm font-medium">Supported formats</p>
                    <p className="text-xs text-muted-foreground">
                        CSV, XLS, XLSX files
                    </p>
                </div>
            </div>

            <div className="flex flex-col gap-3">
                <Button
                    size="lg"
                    onClick={() => setIsDrawerOpen(true)}
                    className="group gap-2 px-8"
                >
                    <Upload className="h-4 w-4" />
                    Import Transactions
                </Button>

                {hasImported && (
                    <Button
                        variant="outline"
                        size="lg"
                        onClick={onComplete}
                        className="group gap-2"
                    >
                        Continue
                        <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                    </Button>
                )}
            </div>

            <ImportTransactionsDrawer
                open={isDrawerOpen}
                onOpenChange={handleDrawerClose}
            />
        </div>
    );
}

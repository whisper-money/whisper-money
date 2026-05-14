import { StepHeader } from '@/components/onboarding/step-header';
import { ImportTransactionsDrawer } from '@/components/transactions/import-transactions-drawer';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { ArrowRight, FileSpreadsheet, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

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
    const { accounts, categories, banks, automationRules } = usePage<{
        accounts: Account[];
        categories: Category[];
        banks: Bank[];
        automationRules: AutomationRule[];
    }>().props;

    // Refresh shared props so the newly created account is available
    useEffect(() => {
        if (accounts.length === 0) {
            router.reload({
                only: ['accounts', 'categories', 'banks', 'automationRules'],
            });
        }
    }, [accounts.length]);

    const handleDrawerClose = (open: boolean) => {
        setIsDrawerOpen(open);
        if (!open) {
            setHasImported(true);
        }
    };

    useEffect(() => {
        if (hasImported) {
            onComplete();
        }
    }, [hasImported, onComplete]);

    const description = useMemo(() => {
        return account
            ? __(
                  "Import transactions for your account. You can export transaction history from your bank's website.",
              )
            : __(
                  'Import your transaction history to start tracking your finances.',
              );
    }, [account]);

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Upload}
                iconContainerClassName="bg-gradient-to-br from-indigo-400 to-purple-500"
                title={__('Import Your Transactions')}
                description={description}
            />

            <div className="mb-4 w-full max-w-md rounded-xl border bg-card p-6">
                <h3 className="mb-4 font-semibold">
                    {__('How to Export from Your Bank:')}
                </h3>
                <ol className="space-y-3 text-sm text-muted-foreground">
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            1
                        </span>
                        <span>
                            {__("Log in to your bank's website or app")}
                        </span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            2
                        </span>
                        <span>
                            {__("Go to your account's transaction history")}
                        </span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            3
                        </span>
                        <span>
                            {__('Look for "Export" or "Download" option')}
                        </span>
                    </li>
                    <li className="flex gap-3">
                        <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary">
                            4
                        </span>
                        <span>{__('Download as CSV or Excel format')}</span>
                    </li>
                </ol>
            </div>

            <div className="mb-6 flex w-full max-w-md items-center gap-4 rounded-lg border border-dashed border-muted-foreground/30 p-4">
                <FileSpreadsheet className="size-10 rounded-full bg-muted p-2.5 text-muted-foreground" />
                <div className="flex flex-col gap-0.5">
                    <p className="text-sm font-medium">
                        {__('Supported formats')}
                    </p>
                    <p className="text-xs text-muted-foreground">
                        {__('CSV, XLS, XLSX files')}
                    </p>
                </div>
            </div>

            <div className="flex w-full flex-col gap-3 sm:w-auto">
                <Button
                    size="lg"
                    onClick={() => setIsDrawerOpen(true)}
                    className="group w-full gap-2 !px-8 py-6 sm:w-auto"
                >
                    <Upload className="h-4 w-4" />
                    {__('Import Transactions')}
                </Button>

                {hasImported && (
                    <Button
                        variant="outline"
                        size="lg"
                        onClick={onComplete}
                        className="group gap-2"
                    >
                        {__('Continue')}

                        <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                    </Button>
                )}
            </div>

            <ImportTransactionsDrawer
                open={isDrawerOpen}
                onOpenChange={handleDrawerClose}
                accounts={accounts}
                categories={categories}
                banks={banks}
                automationRules={automationRules}
                autoSelectSingleAccount
            />
        </div>
    );
}

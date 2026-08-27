import { StepButton } from '@/components/onboarding/step-button';
import {
    StepList,
    StepNumber,
    StepRow,
} from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { ImportTransactionsDrawer } from '@/components/transactions/import-transactions-drawer';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { type Account, type Bank } from '@/types/account';
import { type AutomationRule } from '@/types/automation-rule';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { FileSpreadsheet, Upload } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface StepImportTransactionsProps {
    account: CreatedAccount | undefined;
    onComplete: () => void;
}

const exportSteps = [
    "Log in to your bank's website or app",
    "Go to your account's transaction history",
    'Look for "Export" or "Download" option',
    'Download as CSV or Excel format',
];

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
        <StepScreen
            title={__('Import Your Transactions')}
            description={description}
            footer={
                <>
                    <StepButton
                        text={__('Import Transactions')}
                        icon={Upload}
                        onClick={() => setIsDrawerOpen(true)}
                    />
                    {hasImported && (
                        <StepButton
                            text={__('Continue')}
                            variant="ghost"
                            onClick={onComplete}
                        />
                    )}
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {exportSteps.map((step, index) => (
                        <StepRow
                            key={step}
                            leading={<StepNumber>{index + 1}</StepNumber>}
                            title={__(step)}
                        />
                    ))}
                </StepList>

                <div className="flex flex-col items-center gap-2.5 rounded-lg border border-dashed p-6 text-center">
                    <FileSpreadsheet className="size-6 text-muted-foreground" />
                    <div className="flex flex-col gap-0.5">
                        <p className="text-base font-medium">
                            {__('Supported formats')}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {__('CSV, XLS, XLSX, Numbers files')}
                        </p>
                    </div>
                </div>
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
        </StepScreen>
    );
}

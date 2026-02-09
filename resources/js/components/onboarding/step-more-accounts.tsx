import { StepHeader } from '@/components/onboarding/step-header';
import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { formatAccountType } from '@/types/account';
import { __ } from '@/utils/i18n';
import { Check, CheckCircle2, Plus, Wallet } from 'lucide-react';
import { StepButton } from './step-button';

interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    type: string;
    currency_code: string;
    bank_id: string;
    bank?: {
        id: string;
        name: string;
        logo: string | null;
    };
}

interface StepMoreAccountsProps {
    createdAccounts: CreatedAccount[];
    existingAccounts?: ExistingAccount[];
    onAddMore: () => void;
    onFinish: () => void;
}

export function StepMoreAccounts({
    createdAccounts,
    existingAccounts = [],
    onAddMore,
    onFinish,
}: StepMoreAccountsProps) {
    const description = __(
        'Would you like to add more accounts or continue to the dashboard?',
    );

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={Wallet}
                iconContainerClassName="bg-gradient-to-br from-teal-400 to-cyan-500"
                title={__('Great Progress!')}
                description={description}
            />

            <div className="mb-8 w-full max-w-md">
                <h3 className="mb-3 text-sm font-medium text-muted-foreground">
                    {__('Your Accounts')}
                </h3>
                <div className="space-y-2">
                    {createdAccounts.map((account) => (
                        <div
                            key={account.id}
                            className="flex items-center gap-3 rounded-lg border bg-card p-4"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <div className="flex-1">
                                <p className="font-medium">{account.name}</p>
                                <p className="text-sm text-muted-foreground">
                                    {formatAccountType(account.type)} •{' '}
                                    {account.currencyCode}
                                </p>
                            </div>
                            <Check className="h-5 w-5 text-emerald-500" />
                        </div>
                    ))}
                    {existingAccounts.map((account) => (
                        <div
                            key={account.id}
                            className="flex items-center gap-3 rounded-lg border bg-card p-4"
                        >
                            <div className="flex h-10 w-10 items-center justify-center rounded-full bg-emerald-100 dark:bg-emerald-900/30">
                                <CheckCircle2 className="h-5 w-5 text-emerald-600 dark:text-emerald-400" />
                            </div>
                            <div className="flex-1">
                                <p className="font-medium text-muted-foreground">
                                    {account.bank?.name || 'Account'}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {formatAccountType(account.type)} •{' '}
                                    {account.currency_code}
                                </p>
                            </div>
                            <Check className="h-5 w-5 text-emerald-500" />
                        </div>
                    ))}
                </div>
            </div>

            <div className="mb-6 w-full max-w-md rounded-xl border-2 border-dashed border-muted-foreground/20 p-6">
                <div className="text-center">
                    <h3 className="mb-1 font-semibold">
                        {__('Add More Accounts?')}
                    </h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        {__(
                            'Track all your finances in one place \u2014 checking,\n                        savings, credit cards, investments, and more.',
                        )}
                    </p>
                    <Button
                        variant="outline"
                        onClick={onAddMore}
                        className="w-full gap-2 !py-6"
                    >
                        <Plus className="h-4 w-4" />
                        {__('Add Another Account')}
                    </Button>
                </div>
            </div>

            <StepButton text={__('Finish Setup')} onClick={onFinish} />
        </div>
    );
}

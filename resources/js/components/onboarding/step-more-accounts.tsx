import { Button } from '@/components/ui/button';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { formatAccountType } from '@/types/account';
import { Check, CheckCircle2, Plus, Wallet } from 'lucide-react';

interface ExistingAccount {
    id: string;
    name: string;
    name_iv: string;
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
    const totalAccounts = createdAccounts.length + existingAccounts.length;

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-teal-400 to-cyan-500 shadow-lg duration-500 zoom-in">
                <Wallet className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Great Progress!
            </h1>

            <p className="mb-8 max-w-md text-center text-muted-foreground">
                You've set up {totalAccounts} account
                {totalAccounts !== 1 ? 's' : ''}. Would you like to add more or
                continue to the dashboard?
            </p>

            <div className="mb-8 w-full max-w-md">
                <h3 className="mb-3 text-sm font-medium text-muted-foreground">
                    Your Accounts
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
                    <h3 className="mb-2 font-semibold">Add More Accounts?</h3>
                    <p className="mb-4 text-sm text-muted-foreground">
                        Track all your finances in one place — checking,
                        savings, credit cards, investments, and more.
                    </p>
                    <Button
                        variant="outline"
                        onClick={onAddMore}
                        className="gap-2"
                    >
                        <Plus className="h-4 w-4" />
                        Add Another Account
                    </Button>
                </div>
            </div>

            <Button size="lg" onClick={onFinish} className="gap-2 px-8">
                Finish Setup
            </Button>
        </div>
    );
}

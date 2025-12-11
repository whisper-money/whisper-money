import { StepButton } from '@/components/onboarding/step-button';
import { StepHeader } from '@/components/onboarding/step-header';
import { AmountInput } from '@/components/ui/amount-input';
import { Label } from '@/components/ui/label';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { AlertCircle, TrendingUp, Wallet } from 'lucide-react';
import { useMemo, useState } from 'react';

interface StepImportBalancesProps {
    account: CreatedAccount | undefined;
    onComplete: () => void;
}

export function StepImportBalances({
    account,
    onComplete,
}: StepImportBalancesProps) {
    const [balanceInCents, setBalanceInCents] = useState(0);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setError(null);

        if (balanceInCents === 0) {
            setError('Please enter a balance');
            return;
        }

        setIsSubmitting(true);

        try {
            // TODO: Save balance to backend
            onComplete();
        } catch (err) {
            console.error('Failed to set balance:', err);
            setError('Failed to set balance. Please try again.');
            setIsSubmitting(false);
        }
    }

    const description = useMemo(() => {
        return account
            ? `"${account.name}" is a ${account.type} account. These accounts track balance changes over time instead of individual transactions.`
            : 'Set the current balance for this account to start tracking.';
    }, [account]);

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <StepHeader
                icon={TrendingUp}
                iconContainerClassName="bg-gradient-to-br from-amber-400 to-orange-500"
                title="Set Account Balance"
                description={description}
            />

            <div className="mb-6 w-full max-w-md rounded-xl border bg-card p-6">
                <div className="mb-4 flex items-center gap-3">
                    <div className="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-100 dark:bg-amber-900/30">
                        <Wallet className="h-5 w-5 text-amber-600 dark:text-amber-400" />
                    </div>
                    <div>
                        <h3 className="font-semibold">Balance Tracking</h3>
                        <p className="text-sm text-muted-foreground">
                            Perfect for investment portfolios and retirement
                            accounts
                        </p>
                    </div>
                </div>

                <ul className="space-y-2 text-sm text-muted-foreground">
                    <li className="flex items-center gap-2">
                        <div className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Update balances periodically to track growth
                    </li>
                    <li className="flex items-center gap-2">
                        <div className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        Import balance history from CSV files
                    </li>
                    <li className="flex items-center gap-2">
                        <div className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                        View balance evolution over time
                    </li>
                </ul>
            </div>

            <form onSubmit={handleSubmit} className="w-full max-w-md space-y-4">
                <div className="space-y-2">
                    <Label htmlFor="balance">Current Balance</Label>
                    <AmountInput
                        id="balance"
                        value={balanceInCents}
                        onChange={setBalanceInCents}
                        currencyCode={account?.currencyCode || 'USD'}
                        disabled={isSubmitting}
                        required
                    />
                </div>

                {error && (
                    <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <StepButton
                    type="submit"
                    text="Save Balance"
                    loading={isSubmitting}
                    loadingText="Saving..."
                />
            </form>
        </div>
    );
}

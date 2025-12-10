import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { AlertCircle, ArrowRight, TrendingUp, Wallet } from 'lucide-react';
import { useState } from 'react';

interface StepImportBalancesProps {
    account: CreatedAccount | undefined;
    onComplete: () => void;
}

export function StepImportBalances({
    account,
    onComplete,
}: StepImportBalancesProps) {
    const [balance, setBalance] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    async function handleSubmit(e: React.FormEvent<HTMLFormElement>) {
        e.preventDefault();
        setError(null);

        if (!balance.trim()) {
            setError('Please enter a balance');
            return;
        }

        const numericBalance = parseFloat(balance.replace(/[^0-9.-]/g, ''));
        if (isNaN(numericBalance)) {
            setError('Please enter a valid number');
            return;
        }

        setIsSubmitting(true);

        try {
            onComplete();
        } catch (err) {
            console.error('Failed to set balance:', err);
            setError('Failed to set balance. Please try again.');
            setIsSubmitting(false);
        }
    }

    const handleSkip = () => {
        onComplete();
    };

    return (
        <div className="flex animate-in flex-col items-center duration-500 fade-in slide-in-from-bottom-4">
            <div className="mb-8 flex h-20 w-20 animate-in items-center justify-center rounded-full bg-gradient-to-br from-amber-400 to-orange-500 shadow-lg duration-500 zoom-in">
                <TrendingUp className="h-10 w-10 text-white" />
            </div>

            <h1 className="mb-4 text-center text-3xl font-bold tracking-tight">
                Set Account Balance
            </h1>

            <p className="mb-8 max-w-md text-center text-muted-foreground">
                {account
                    ? `"${account.name}" is a ${account.type} account. These accounts track balance changes over time instead of individual transactions.`
                    : 'Set the current balance for this account to start tracking.'}
            </p>

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
                    <div className="relative">
                        <span className="absolute top-1/2 left-3 -translate-y-1/2 text-muted-foreground">
                            {account?.currencyCode || '$'}
                        </span>
                        <Input
                            id="balance"
                            type="text"
                            inputMode="decimal"
                            value={balance}
                            onChange={(e) => setBalance(e.target.value)}
                            placeholder="0.00"
                            className="pl-12"
                            disabled={isSubmitting}
                        />
                    </div>
                </div>

                {error && (
                    <div className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="h-4 w-4 shrink-0" />
                        {error}
                    </div>
                )}

                <div className="flex gap-3">
                    <Button
                        type="button"
                        variant="outline"
                        size="lg"
                        className="flex-1"
                        onClick={handleSkip}
                        disabled={isSubmitting}
                    >
                        Skip for Now
                    </Button>
                    <Button
                        type="submit"
                        size="lg"
                        className="group flex-1 gap-2"
                        disabled={isSubmitting}
                    >
                        {isSubmitting && <Spinner className="mr-2" />}
                        {isSubmitting ? 'Saving...' : 'Save Balance'}
                        {!isSubmitting && (
                            <ArrowRight className="h-4 w-4 transition-transform group-hover:translate-x-1" />
                        )}
                    </Button>
                </div>
            </form>
        </div>
    );
}

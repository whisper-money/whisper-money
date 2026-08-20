import { StepButton } from '@/components/onboarding/step-button';
import {
    StepField,
    StepScreen,
    stepControlClass,
} from '@/components/onboarding/step-screen';
import { AmountInput } from '@/components/ui/amount-input';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { __ } from '@/utils/i18n';
import { AlertCircle } from 'lucide-react';
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

        setIsSubmitting(true);

        try {
            // TODO: Save balance to backend
            onComplete();
        } catch (err) {
            console.error('Failed to set balance:', err);
            setError(__('Failed to set balance. Please try again.'));
            setIsSubmitting(false);
        }
    }

    const description = useMemo(() => {
        return account
            ? __(
                  'This account tracks balance changes over time instead of individual transactions.',
              )
            : __('Set the current balance for this account to start tracking.');
    }, [account]);

    return (
        <StepScreen
            title={__('Set Account Balance')}
            description={description}
            footer={
                <StepButton
                    type="submit"
                    form="onboarding-balance"
                    text={__('Save Balance')}
                    loading={isSubmitting}
                    loadingText={__('Saving...')}
                />
            }
        >
            <form
                id="onboarding-balance"
                onSubmit={handleSubmit}
                className="flex flex-col gap-5"
            >
                <StepField label={__('Current Balance')} htmlFor="balance">
                    <AmountInput
                        id="balance"
                        value={balanceInCents}
                        onChange={setBalanceInCents}
                        currencyCode={account?.currencyCode || 'USD'}
                        disabled={isSubmitting}
                        className={stepControlClass}
                    />
                </StepField>

                {error && (
                    <p className="flex items-center gap-2 rounded-lg bg-destructive/10 p-3 text-sm text-destructive">
                        <AlertCircle className="size-4 shrink-0" />
                        {error}
                    </p>
                )}
            </form>

            <ul className="flex flex-col gap-2.5 text-sm text-muted-foreground">
                {[
                    __('Update balances periodically to track growth'),
                    __('Import balance history from CSV files'),
                    __('View balance evolution over time'),
                ].map((line) => (
                    <li key={line} className="flex items-start gap-2.5">
                        <span className="mt-2 size-1 shrink-0 rounded-full bg-border" />
                        {line}
                    </li>
                ))}
            </ul>
        </StepScreen>
    );
}

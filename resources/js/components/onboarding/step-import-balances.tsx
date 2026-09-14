import { store as storeBalance } from '@/actions/App/Http/Controllers/AccountBalanceController';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepError,
    StepField,
    StepScreen,
    stepControlClass,
} from '@/components/onboarding/step-screen';
import { AmountInput } from '@/components/ui/amount-input';
import { CreatedAccount } from '@/hooks/use-onboarding-state';
import { getCsrfToken } from '@/lib/csrf';
import { __ } from '@/utils/i18n';
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

        // Reached by a deep link or a refresh, with the account the balance
        // belongs to no longer in hand: there is nothing to save, so move on
        // rather than trap the user on a form that cannot go anywhere.
        if (!account) {
            onComplete();

            return;
        }

        setIsSubmitting(true);

        try {
            const response = await fetch(storeBalance.url(account.id), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': getCsrfToken(),
                    Accept: 'application/json',
                },
                body: JSON.stringify({
                    balance_date: new Date().toISOString().split('T')[0],
                    balance: balanceInCents,
                }),
            });

            if (!response.ok) {
                throw new Error(`Balance request failed: ${response.status}`);
            }

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

                {error && <StepError>{error}</StepError>}
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

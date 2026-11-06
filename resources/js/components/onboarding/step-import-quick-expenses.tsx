import { quickExpenses } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepError,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { AmountInput } from '@/components/ui/amount-input';
import { captureEvent } from '@/lib/posthog';
import { type UUID } from '@/types/uuid';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import {
    Bus,
    House,
    Landmark,
    Plug,
    Repeat,
    ShieldCheck,
    ShoppingBasket,
    Utensils,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';

/**
 * The expenses most months are made of, in the order people think of them.
 * The keys are `App\Enums\QuickExpense`, which files each under the user's own
 * default category.
 */
export const QUICK_EXPENSES: {
    kind: string;
    label: string;
    icon: LucideIcon;
}[] = [
    { kind: 'rent', label: 'Rent', icon: House },
    { kind: 'mortgage', label: 'Mortgage', icon: Landmark },
    { kind: 'groceries', label: 'Groceries', icon: ShoppingBasket },
    { kind: 'utilities', label: 'Utilities', icon: Plug },
    { kind: 'subscriptions', label: 'Subscriptions', icon: Repeat },
    { kind: 'transport', label: 'Transport', icon: Bus },
    { kind: 'eating_out', label: 'Eating out', icon: Utensils },
    { kind: 'insurance', label: 'Insurance', icon: ShieldCheck },
];

interface StepImportQuickExpensesProps {
    accountId: UUID;
    currencyCode: string;
    onComplete: () => void;
    onBack: () => void;
}

/**
 * The import step for someone with no file at hand: a month of their main
 * expenses, in round figures, so the reveal has spending to show. Each one is
 * stored as a real transaction dated last month and labelled "Estimate".
 */
export function StepImportQuickExpenses({
    accountId,
    currencyCode,
    onComplete,
    onBack,
}: StepImportQuickExpensesProps) {
    const [amounts, setAmounts] = useState<Record<string, number>>({});
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const entries = QUICK_EXPENSES.filter(
        ({ kind }) => (amounts[kind] ?? 0) > 0,
    );

    const handleSave = () => {
        setIsSaving(true);
        setError(null);

        axios
            .post(quickExpenses().url, {
                account_id: accountId,
                expenses: entries.map(({ kind }) => ({
                    kind,
                    amount: amounts[kind],
                })),
            })
            .then(() => {
                // How many, never how much: the amounts stay the user's.
                captureEvent('onboarding_quick_expenses_submitted', {
                    count: entries.length,
                });
                onComplete();
            })
            .catch((failure: unknown) => {
                // A refusal the server explains (an account that cannot take
                // movements) is worth more than a retry that cannot work.
                const message = axios.isAxiosError(failure)
                    ? (failure.response?.data as { message?: string })?.message
                    : undefined;

                setError(
                    axios.isAxiosError(failure) &&
                        failure.response?.status === 422 &&
                        message
                        ? message
                        : __("We couldn't save them. Please try again."),
                );
                setIsSaving(false);
            });
    };

    return (
        <StepScreen
            title={__('Your main monthly expenses')}
            description={__(
                'Round figures are fine. Fill in the ones you have and leave the rest empty.',
            )}
            footer={
                <>
                    <StepButton
                        text={
                            entries.length === 1
                                ? __('Save 1 expense')
                                : __('Save :count expenses', {
                                      count: entries.length,
                                  })
                        }
                        disabled={entries.length === 0}
                        loading={isSaving}
                        onClick={handleSave}
                    />
                    <StepButton
                        text={__('Back to the file')}
                        variant="ghost"
                        onClick={onBack}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-6">
                <StepList>
                    {QUICK_EXPENSES.map(({ kind, label, icon }) => (
                        <StepRow
                            key={kind}
                            icon={icon}
                            title={
                                <label htmlFor={`quick-expense-${kind}`}>
                                    {__(label)}
                                </label>
                            }
                            trailing={
                                <AmountInput
                                    id={`quick-expense-${kind}`}
                                    value={amounts[kind] ?? 0}
                                    onChange={(value) =>
                                        setAmounts((previous) => ({
                                            ...previous,
                                            [kind]: value,
                                        }))
                                    }
                                    currencyCode={currencyCode}
                                    disabled={isSaving}
                                    commitOnChange
                                    className="h-11 w-36 shrink-0 text-base md:text-base"
                                />
                            }
                        />
                    ))}
                </StepList>

                {error && <StepError>{error}</StepError>}

                <StepCallout>
                    {__(
                        'We save them as last month’s movements, labelled “Estimate”. Edit or delete them whenever you like — and if you import that month later, we offer to remove them.',
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}

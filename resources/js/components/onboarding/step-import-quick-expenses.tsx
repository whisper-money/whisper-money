import { quickExpenses } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import { ImportLinkRow } from '@/components/onboarding/step-import-link';
import {
    StepChevron,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepError,
    StepFilled,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { AmountInput } from '@/components/ui/amount-input';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { captureEvent } from '@/lib/posthog';
import { type SharedData } from '@/types';
import { type UUID } from '@/types/uuid';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import {
    Bus,
    FileSpreadsheet,
    House,
    Landmark,
    Plug,
    Repeat,
    ShieldCheck,
    ShoppingBasket,
    Utensils,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

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

/**
 * Where the screen was opened from: the upload screen's button, the phone's
 * default for the step, or the reveal's offer to fill an empty month.
 */
export type QuickExpensesSource = 'upload' | 'default' | 'reveal';

interface StepImportQuickExpensesProps {
    accountId: UUID;
    currencyCode: string;
    /**
     * The monthly spending the user guessed earlier in the onboarding, in minor
     * units of their own currency. Drives the running counter.
     */
    spendingGuess?: number;
    /** Called once the expenses are saved, and when the screen is skipped. */
    onComplete: () => void;
    onBack?: () => void;
    /** Back to the file, for someone who turns out to have one. */
    onUpload?: () => void;
    /** Offered on a phone only, like on the upload screen. */
    importLink?: { accountId: UUID; onSent: () => void };
    source: QuickExpensesSource;
}

/**
 * The import step for someone with no file at hand: a month of their main
 * expenses, in round figures, so the reveal has spending to show. Each one is
 * stored as a real transaction dated last month and labelled "Estimate".
 */
export function StepImportQuickExpenses({
    accountId,
    currencyCode,
    spendingGuess,
    onComplete,
    onBack,
    onUpload,
    importLink: importLinkOption,
    source,
}: StepImportQuickExpensesProps) {
    const isMobile = useIsMobile();
    const userCurrency = usePage<SharedData>().props.auth.user.currency_code;
    const [amounts, setAmounts] = useState<Record<string, number>>({});
    const [isSaving, setIsSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const reportedOpen = useRef(false);
    const importLink = isMobile ? importLinkOption : undefined;

    // Reported here rather than by each caller, so every way in is counted
    // once and under its own name.
    useEffect(() => {
        if (reportedOpen.current) {
            return;
        }

        reportedOpen.current = true;
        captureEvent('onboarding_quick_expenses_opened', { source });
    }, [source]);

    const entries = QUICK_EXPENSES.filter(
        ({ kind }) => (amounts[kind] ?? 0) > 0,
    );
    const total = entries.reduce((sum, { kind }) => sum + amounts[kind], 0);
    // The guess was made in the user's currency: set against an account in
    // another one, the two numbers do not measure the same thing.
    const guess =
        spendingGuess && spendingGuess > 0 && currencyCode === userCurrency
            ? spendingGuess
            : undefined;

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
            title={__('Last month, in round figures')}
            description={__(
                'Two minutes. Fill in the ones you have and leave the rest empty — you can bring the real movements in later.',
            )}
            footer={
                <>
                    {guess !== undefined && (
                        <GuessCounter
                            total={total}
                            guess={guess}
                            currencyCode={currencyCode}
                        />
                    )}
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
                        text={__('Skip for now')}
                        variant="ghost"
                        className="h-10 text-sm"
                        disabled={isSaving}
                        onClick={onComplete}
                    />
                    {onBack && (
                        <StepButton
                            text={__('Back')}
                            variant="ghost"
                            className="h-10 text-sm"
                            disabled={isSaving}
                            onClick={onBack}
                        />
                    )}
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

                {(onUpload || importLink) && (
                    <StepList>
                        {onUpload && (
                            <StepRow
                                icon={FileSpreadsheet}
                                title={__('Have the bank file?')}
                                description={__(
                                    'Upload it instead — real movements, not estimates',
                                )}
                                trailing={<StepChevron />}
                                onClick={isSaving ? undefined : onUpload}
                            />
                        )}
                        {importLink && (
                            <ImportLinkRow
                                accountId={importLink.accountId}
                                onSent={importLink.onSent}
                                title={__('Rather do it on a computer?')}
                                description={__(
                                    'Email me a link to import from there',
                                )}
                                disabled={isSaving}
                            />
                        )}
                    </StepList>
                )}

                <StepCallout>
                    {__(
                        'We save them as last month’s movements, labelled “Estimate”. Import that month later and we offer to remove them.',
                    )}
                </StepCallout>
            </div>
        </StepScreen>
    );
}

/**
 * The running total set against the guess from earlier in the onboarding, so
 * each figure typed in visibly closes the gap — and says so once it is past it.
 */
function GuessCounter({
    total,
    guess,
    currencyCode,
}: {
    total: number;
    guess: number;
    currencyCode: string;
}) {
    const locale = useLocale();
    const money = (amount: number) =>
        formatCurrency(amount, currencyCode, locale, 0, 0);
    const strong = (
        <span className="font-semibold text-foreground tabular-nums">
            {money(total)}
        </span>
    );
    const past = total >= guess;
    const percentage = Math.min(100, Math.round((total / guess) * 100));

    return (
        <div className="flex flex-col gap-2 pb-1">
            <div className="flex items-baseline justify-between gap-3 text-sm text-muted-foreground">
                <span>
                    <StepFilled
                        sentence={
                            past
                                ? __('Already past your guess: :total')
                                : __('So far :total')
                        }
                        values={{ total: strong }}
                    />
                </span>
                <span className="text-right tabular-nums">
                    {past
                        ? __('guess :guess', { guess: money(guess) })
                        : __('of the :guess you guessed', {
                              guess: money(guess),
                          })}
                </span>
            </div>
            <Progress
                value={percentage}
                // The app's wrapper keeps `value` for the fill and never hands
                // it to Radix, so the bar would announce no value at all.
                aria-valuenow={percentage}
                aria-label={__('Spending so far against your guess')}
                className="h-1.5"
            />
        </div>
    );
}

import { StepButton } from '@/components/onboarding/step-button';
import {
    StepCheck,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepError,
    StepFilled,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { useLocale } from '@/hooks/use-locale';
import {
    useOnboardingSummary,
    type OnboardingSummary,
} from '@/hooks/use-onboarding-summary';
import { captureEvent } from '@/lib/posthog';
import { target as targetRoute } from '@/routes/onboarding';
import {
    formatCurrency,
    getCurrencySymbol,
    toMajorUnits,
    toMinorUnits,
} from '@/utils/currency';
import { __ } from '@/utils/i18n';
import axios from 'axios';
import { Minus, Plus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** The stepper's granularity, in major units — the same coarseness as step 4. */
const STEP = 50;

/** Where the stepper opens: a tenth of the month, which most people keep. */
const DEFAULT_SHARE = 0.1;

/** What a month is worth said as a year, which is the point of saying it. */
const MONTHS_IN_YEAR = 12;

/** Under two, the sentence about repeat charges has no plural to stand on. */
const MIN_RECURRING = 2;

interface StepTargetProps {
    /** The goal picked in step 2, which decides how the target is framed. */
    goal?: string;
    /** The user's own guess from step 4, in minor units. */
    spendingGuess?: number;
    /** On to the close, whether a target was set or declined. */
    onContinue: () => void;
}

/**
 * Step 10, and the first thing in the flow the user is asked to decide rather
 * than to hand over. It is built out of the two things they already gave: the
 * goal they picked in step 2 and the month step 7 read back to them.
 *
 * It is optional on purpose. Accounts, movements and categories were the
 * product's requirement; a target never was, and this sits at the end of a long
 * flow just after the user paid — the worst possible place for a wall.
 */
export function StepTarget({
    goal,
    spendingGuess,
    onContinue,
}: StepTargetProps) {
    const summary = useOnboardingSummary();
    const onContinueRef = useRef(onContinue);
    onContinueRef.current = onContinue;

    // A summary that never arrived has nothing to say and no way to say it, so
    // it moves on the way the reveal does when its own numbers fail to land.
    useEffect(() => {
        if (summary === null) {
            onContinueRef.current();
        }
    }, [summary]);

    if (summary === undefined || summary === null) {
        return <TargetSkeleton />;
    }

    const spending = summary.monthly_spending;

    // Someone who brought a pension and a mortgage has no month to build a
    // target on. Step 5 promised them this screen — "your first target comes
    // out of it" — so it says why there is no number rather than vanishing
    // between the step before it and the step after.
    if (spending === null) {
        return <NoSpendingToTarget onContinue={onContinue} />;
    }

    return (
        <Target
            summary={summary}
            spending={spending}
            goal={goal}
            spendingGuess={spendingGuess}
            onContinue={onContinue}
        />
    );
}

/** The shape of the screen, while the month behind it is counted. */
function TargetSkeleton() {
    return (
        <StepScreen>
            <div className="flex flex-col gap-7">
                <div className="flex flex-col gap-2.5">
                    <Skeleton className="h-8 w-56" />
                    <Skeleton className="h-4 w-full" />
                    <Skeleton className="h-4 w-3/4" />
                </div>
                <Skeleton className="mx-auto h-16 w-40" />
                <Skeleton className="h-28 w-full" />
            </div>
        </StepScreen>
    );
}

/**
 * The step for a reader with balances but no spending behind them — a pension,
 * a mortgage, a broker, or a bank that has handed over nothing going out yet.
 *
 * A target set on no spending would be a number the app invented, so none is
 * offered. What the screen owes them is the reason, because step 5 told them
 * this was coming and silence reads as a step that broke.
 */
function NoSpendingToTarget({ onContinue }: { onContinue: () => void }) {
    return (
        <StepScreen
            title={__('Your target can wait')}
            description={__(
                'A target is built on a month of real spending, and there isn’t one to read yet. Inventing a number here would only give you something to ignore.',
            )}
            footer={<StepButton text={__('Continue')} onClick={onContinue} />}
        >
            <StepList>
                <StepRow
                    leading={<StepCheck />}
                    title={__('Nothing is missing')}
                    description={__(
                        'Everything you brought in is saved and on your dashboard.',
                    )}
                />
                <StepRow
                    leading={<StepCheck />}
                    title={__('It comes back on its own')}
                    description={__(
                        'Once a month of movements is in, set one from Planning in a couple of taps.',
                    )}
                />
            </StepList>
        </StepScreen>
    );
}

function Target({
    summary,
    spending,
    goal,
    spendingGuess,
    onContinue,
}: {
    summary: OnboardingSummary;
    /** Minor units, positive: the month the reveal was written about. */
    spending: number;
    goal?: string;
    spendingGuess?: number;
    onContinue: () => void;
}) {
    const locale = useLocale();
    const currency = summary.currency_code;
    const spendingMajor = toMajorUnits(spending, currency);
    const ceiling = Math.max(STEP, Math.floor(spendingMajor / STEP) * STEP);

    const [amount, setAmount] = useState(() =>
        opening(summary.target, currency, spendingMajor, ceiling),
    );
    const [warn, setWarn] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [failed, setFailed] = useState(false);

    const money = (minor: number) =>
        formatCurrency(minor, currency, locale, 0, 0);
    const monthly = toMinorUnits(amount, currency);

    const handleSet = () => {
        setIsSaving(true);
        setFailed(false);

        axios
            .post(targetRoute().url, { amount: monthly, warn })
            .then(() => {
                captureEvent('onboarding_target_set', {
                    amount: monthly,
                    spending,
                    warn,
                });
                onContinue();
            })
            .catch(() => {
                setFailed(true);
                setIsSaving(false);
            });
    };

    const handleDecline = () => {
        captureEvent('onboarding_target_declined', { spending });
        onContinue();
    };

    return (
        <StepScreen
            title={__('Your first target')}
            description={
                <Built
                    spending={money(spending)}
                    guess={
                        spendingGuess === undefined
                            ? undefined
                            : money(spendingGuess)
                    }
                    goal={goal}
                />
            }
            footer={
                <>
                    {failed && (
                        <StepError>
                            {__(
                                'That target did not save. Try again, or carry on without one.',
                            )}
                        </StepError>
                    )}
                    <StepButton
                        text={__('Set my target')}
                        onClick={handleSet}
                        loading={isSaving}
                        loadingText={__('Setting it up…')}
                    />
                    <StepButton
                        text={__('Not yet')}
                        variant="ghost"
                        onClick={handleDecline}
                    />
                </>
            }
        >
            <div className="flex flex-col gap-5">
                <Stepper
                    amount={amount}
                    currency={currency}
                    locale={locale}
                    min={STEP}
                    max={ceiling}
                    onChange={setAmount}
                />

                <StepList>
                    <StepRow
                        title={__('In a year')}
                        trailing={
                            <span className="text-[17px] font-semibold tabular-nums">
                                {money(monthly * MONTHS_IN_YEAR)}
                            </span>
                        }
                    />
                    <StepRow
                        title={__('Warn me before I overspend')}
                        description={__("Not after, when it's a receipt")}
                        pressed={warn}
                        onClick={() => setWarn(!warn)}
                        trailing={warn ? <StepCheck /> : undefined}
                    />
                </StepList>

                <StepCallout>
                    <Comparison
                        summary={summary}
                        monthly={monthly}
                        money={money}
                    />
                </StepCallout>
            </div>
        </StepScreen>
    );
}

/**
 * The target the stepper opens on: the one already set, if the user is coming
 * back through, and otherwise a share of what they actually spend.
 */
function opening(
    existing: number | null,
    currency: string,
    spendingMajor: number,
    ceiling: number,
): number {
    if (existing !== null) {
        return toMajorUnits(existing, currency);
    }

    const share = Math.round((spendingMajor * DEFAULT_SHARE) / STEP) * STEP;

    return Math.min(Math.max(share, STEP), ceiling);
}

/**
 * The line that makes the target theirs: it is built on the month that actually
 * left the account, set against the month they thought. Not "what you spend":
 * nothing is categorized this early, so the figure carries the user's own
 * transfers with it, and the reveal two steps back says as much.
 *
 * Without a guess on file — a resumed run, mostly — the second half has nothing
 * to compare and is dropped.
 */
function Built({
    spending,
    guess,
    goal,
}: {
    spending: string;
    guess?: string;
    goal?: string;
}) {
    const strong = (amount: string) => (
        <span className="font-medium text-foreground">{amount}</span>
    );

    return (
        <>
            {guess === undefined ? (
                <StepFilled
                    sentence={__(
                        'Built on the :spending that left the account last month.',
                    )}
                    values={{ spending: strong(spending) }}
                />
            ) : (
                <StepFilled
                    sentence={__(
                        'Built on the :spending that left the account last month, not the :guess you thought.',
                    )}
                    values={{
                        spending: strong(spending),
                        guess: strong(guess),
                    }}
                />
            )}{' '}
            {framing(goal)}
        </>
    );
}

/**
 * The half of the line that belongs to step 2, which promised this answer would
 * decide "what your first target will be". A target that reads the same for
 * someone paying down a debt and someone saving for a year off would make that
 * a lie, so the goal picks the sentence.
 */
function framing(goal: string | undefined): string {
    switch (goal) {
        case 'debt':
            return __('Every month you hold it is a month off what you owe.');
        case 'save-for':
            return __('Small enough to keep, and it adds up on its own.');
        case 'understand':
            return __('A number to hold it against, now that you can see it.');
        default:
            return __('Start small enough to keep.');
    }
}

/**
 * What the target is worth in the user's own spending, and what the app will do
 * about it. The comparison is only drawn when there are repeat charges to draw
 * it against — a made-up equivalence would be exactly the kind of thing this
 * screen exists to avoid.
 */
function Comparison({
    summary,
    monthly,
    money,
}: {
    summary: OnboardingSummary;
    monthly: number;
    money: (amount: number) => string;
}) {
    const promise = __("We'll tell you the day you drift off it.");

    if (
        summary.recurring_count < MIN_RECURRING ||
        summary.recurring_amount <= 0
    ) {
        return promise;
    }

    return (
        <>
            <StepFilled
                sentence={__(
                    "That's :share of the :amount that :count merchants bill you every month, unchanged.",
                )}
                values={{
                    share: (
                        <span className="font-medium text-foreground">
                            {`${Math.round((monthly / summary.recurring_amount) * 100)}%`}
                        </span>
                    ),
                    amount: (
                        <span className="font-medium text-foreground">
                            {money(summary.recurring_amount)}
                        </span>
                    ),
                    count: summary.recurring_count,
                }}
            />{' '}
            {promise}
        </>
    );
}

/** The number itself, and the two ways to move it. */
function Stepper({
    amount,
    currency,
    locale,
    min,
    max,
    onChange,
}: {
    amount: number;
    currency: string;
    locale: string;
    min: number;
    max: number;
    onChange: (amount: number) => void;
}) {
    const nudge = (by: number) =>
        onChange(Math.min(Math.max(amount + by, min), max));

    return (
        <div className="flex items-center justify-between gap-4 pt-1">
            <Button
                variant="outline"
                size="icon"
                className="size-11 shrink-0 rounded-full"
                aria-label={__('Lower the target')}
                disabled={amount <= min}
                onClick={() => nudge(-STEP)}
            >
                <Minus className="size-5" />
            </Button>

            <div
                className="flex flex-col items-center gap-0.5"
                aria-live="polite"
            >
                <div className="flex items-baseline gap-1">
                    <span className="text-2xl leading-none font-medium text-muted-foreground">
                        {getCurrencySymbol(currency)}
                    </span>
                    <span className="text-[52px] leading-none font-semibold tracking-[-0.04em] tabular-nums">
                        {new Intl.NumberFormat(locale, {
                            maximumFractionDigits: 0,
                        }).format(amount)}
                    </span>
                </div>
                <span className="text-[13px] text-muted-foreground">
                    {__('a month')}
                </span>
            </div>

            <Button
                variant="outline"
                size="icon"
                className="size-11 shrink-0 rounded-full"
                aria-label={__('Raise the target')}
                disabled={amount >= max}
                onClick={() => nudge(STEP)}
            >
                <Plus className="size-5" />
            </Button>
        </div>
    );
}

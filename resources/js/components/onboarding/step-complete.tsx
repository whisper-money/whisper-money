import { complete } from '@/actions/App/Http/Controllers/OnboardingController';
import { StepButton } from '@/components/onboarding/step-button';
import {
    StepCheck,
    StepList,
    StepRow,
} from '@/components/onboarding/step-list';
import {
    StepCallout,
    StepFilled,
    StepScreen,
} from '@/components/onboarding/step-screen';
import { Skeleton } from '@/components/ui/skeleton';
import { useLocale } from '@/hooks/use-locale';
import { clearStoredOnboardingStep } from '@/hooks/use-onboarding-state';
import {
    useOnboardingSummary,
    type OnboardingSummary,
} from '@/hooks/use-onboarding-summary';
import { captureEvent } from '@/lib/posthog';
import type { SharedData } from '@/types';
import { type SignupPlan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

/**
 * Below this the guess and the real number are the same number, and a sentence
 * about the gap between them has no gap to talk about. One unit of the user's
 * currency, in minor units.
 */
const GAP_FLOOR = 100;

interface StepCompleteProps {
    /** Accounts added during this run of the wizard, not counting earlier ones. */
    accountsCreated: number;
    hasConnectedAccount: boolean;
    signupPlan: SignupPlan | null;
    /** The user's own guess from step 4, in minor units. */
    spendingGuess?: number;
}

/**
 * Step 11: an inventory, not a congratulation.
 *
 * Every line is read off what the user actually has, and a line that is not
 * true is not written — no target set, no target line; no bank connected,
 * nothing about accounts keeping themselves up to date. A closing screen that
 * oversells is worse than a sober one, and the dashboard behind it would
 * contradict it within seconds.
 */
export function StepComplete({
    accountsCreated,
    hasConnectedAccount,
    signupPlan,
    spendingGuess,
}: StepCompleteProps) {
    const summary = useOnboardingSummary();
    const locale = useLocale();
    const [isRedirecting, setIsRedirecting] = useState(false);

    const handleComplete = () => {
        setIsRedirecting(true);

        router.post(
            complete.url(),
            {},
            {
                // Onboarding is over, so the locally stored resume step has to
                // go with it or a later visit drags the user back in.
                //
                // The event rides along here rather than on the click: only a
                // request the server accepted actually stamped `onboarded_at`,
                // and the button is deliberately retryable after a failure.
                onSuccess: () => {
                    clearStoredOnboardingStep();
                    captureEvent('onboarding_completed', {
                        accounts_created: accountsCreated,
                        has_connected_account: hasConnectedAccount,
                        signup_plan: signupPlan,
                        target: summary?.target ?? null,
                    });
                },
                // `onError` only fires for a response Inertia could read, so a
                // request that died on the network left the last step of
                // onboarding behind a spinning, disabled button with no way out
                // but a page reload. `onFinish` runs on every outcome, so the
                // toast that already explains the failure comes with a button
                // the user can press again.
                onFinish: () => {
                    setIsRedirecting(false);
                },
            },
        );
    };

    return (
        <StepScreen
            title={__('Your dashboard isn’t empty')}
            description={__(
                'Most finance apps hand you a blank page and a homework assignment. Here’s what’s already in yours.',
            )}
            footer={
                <StepButton
                    text={__('Open my dashboard')}
                    onClick={handleComplete}
                    loading={isRedirecting}
                    loadingText={__('Opening…')}
                />
            }
        >
            {summary === undefined ? (
                <InventorySkeleton />
            ) : (
                // A summary that failed to load leaves the screen with nothing
                // it is allowed to claim, so it claims nothing and still lets
                // the user out.
                summary !== null && (
                    <div className="flex flex-col gap-6">
                        <StepList>
                            {inventory(summary, locale).map((line) => (
                                <StepRow
                                    key={line}
                                    title={line}
                                    leading={<StepCheck />}
                                />
                            ))}
                        </StepList>

                        <Closing
                            summary={summary}
                            spendingGuess={spendingGuess}
                            locale={locale}
                        />
                    </div>
                )
            )}
        </StepScreen>
    );
}

/** The shape of the list, while what goes in it is counted. */
function InventorySkeleton() {
    return (
        <div className="flex flex-col gap-4 border-t pt-4">
            {[0, 1, 2, 3].map((row) => (
                <Skeleton key={row} className="h-5 w-3/4" />
            ))}
        </div>
    );
}

/**
 * What is in the dashboard, in the user's own numbers.
 *
 * Built by asking each fact whether it happened, so the list is whatever came
 * out true: a file import with no bank and no target produces three lines, and
 * all three of them are things the user can go and look at.
 */
function inventory(summary: OnboardingSummary, locale: string): string[] {
    return [
        history(summary.months),
        movements(summary.transactions, summary.rules),
        accounts(summary.accounts, summary.connected_accounts),
        summary.rules > 0 ? __('Everything new sorted from now on') : null,
        putAside(summary.target, summary.currency_code, locale),
    ].filter((line): line is string => line !== null);
}

/** How far back the ledger goes, which is what the charts are drawn over. */
function history(months: number): string | null {
    if (months <= 0) {
        return null;
    }

    return months === 1
        ? __('1 month of history')
        : __(':count months of history', { count: months });
}

/** What came in, and what is filing it. */
function movements(transactions: number, rules: number): string | null {
    if (transactions <= 0) {
        return null;
    }

    const counted =
        transactions === 1
            ? __('1 movement')
            : __(':count movements', { count: transactions });

    if (rules === 0) {
        return counted;
    }

    return rules === 1
        ? __(':movements, 1 rule filing them', { movements: counted })
        : __(':movements, :count rules filing them', {
              movements: counted,
              count: rules,
          });
}

/**
 * How many accounts, and whether they keep themselves up to date.
 *
 * The sync half is only added for someone who connected a bank: an account
 * typed in by hand moves when its owner moves it, and telling them otherwise
 * would be the one promise on this screen nothing behind it keeps. The wording
 * is the accounts hub's own, so the two screens make the same promise.
 */
function accounts(total: number, connected: number): string | null {
    if (total <= 0) {
        return null;
    }

    const counted =
        total === 1 ? __('1 account') : __(':count accounts', { count: total });

    return connected > 0
        ? __(':accounts, syncing daily', { accounts: counted })
        : counted;
}

/** The target, only for someone who set one. */
function putAside(
    target: number | null,
    currency: string,
    locale: string,
): string | null {
    if (target === null) {
        return null;
    }

    return __(':amount a month put aside', {
        amount: formatCurrency(target, currency, locale, 0, 0),
    });
}

/**
 * What the list is left with once it has been read.
 *
 * Someone who brought no movements gets the one thing that would fill the rest
 * of it rather than an empty half-screen: their list is short because their
 * onboarding was, and saying so is better than padding it out.
 */
function Closing({
    summary,
    spendingGuess,
    locale,
}: {
    summary: OnboardingSummary;
    spendingGuess?: number;
    locale: string;
}) {
    if (summary.transactions === 0) {
        return <NothingInYet />;
    }

    return (
        <Gap summary={summary} spendingGuess={spendingGuess} locale={locale} />
    );
}

/** The one way in left to name, which is not the same on every install. */
function NothingInYet() {
    const { openBankingEnabled } = usePage<SharedData>().props;

    return (
        <StepCallout>
            {openBankingEnabled
                ? __(
                      'Connect a bank or import a file whenever you like, and the rest of this list fills itself in.',
                  )
                : __(
                      'Import a file whenever you like, and the rest of this list fills itself in.',
                  )}
        </StepCallout>
    );
}

/**
 * The line the whole flow was built to earn: the guess from step 4 set against
 * the number step 7 answered it with. It needs both, and a gap big enough to be
 * worth naming — someone who guessed right gets no consolation paragraph.
 */
function Gap({
    summary,
    spendingGuess,
    locale,
}: {
    summary: OnboardingSummary;
    spendingGuess?: number;
    locale: string;
}) {
    const spent = summary.monthly_spending;

    if (
        spent === null ||
        spendingGuess === undefined ||
        Math.abs(spent - spendingGuess) < GAP_FLOOR
    ) {
        return null;
    }

    const money = (amount: number) =>
        formatCurrency(amount, summary.currency_code, locale, 0, 0);
    const strong = (amount: number) => (
        <span className="font-medium text-foreground">{money(amount)}</span>
    );

    return (
        <StepCallout>
            <StepFilled
                sentence={__(
                    'You came in guessing :guess. :spent left the account, and now you can see where each part of it went.',
                )}
                values={{ guess: strong(spendingGuess), spent: strong(spent) }}
            />
        </StepCallout>
    );
}

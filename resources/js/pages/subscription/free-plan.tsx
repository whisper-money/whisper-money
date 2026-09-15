import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepCallout, StepScreen } from '@/components/onboarding/step-screen';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { subscribe } from '@/routes';
import { freePlan } from '@/routes/subscribe';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { Check, CircleAlert } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

interface FreePlanPageProps extends SharedData {
    /** The banks that would be disconnected, by name. */
    banks: string[];
    transactionsCount: number;
    hasAiConsent: boolean;
}

/**
 * The confirmation in front of the free plan, and the only irreversible thing
 * the paywall offers.
 *
 * It was a dialog, and a dialog could only say "your banks" in the abstract.
 * What this costs is specific — these banks, this many movements — and the one
 * thing people actually fear is not on the list of what goes: the screen says
 * outright that nothing is deleted, because "we disconnect your banks" reads as
 * "we delete my transactions" to most people.
 */
export default function FreePlanConfirm() {
    const { banks, transactionsCount, hasAiConsent } =
        usePage<FreePlanPageProps>().props;
    const [isLeaving, setIsLeaving] = useState(false);

    const chooseFreePlan = () => {
        captureEvent('paywall_free_plan_confirmed', { banks: banks.length });
        setIsLeaving(true);

        // The server redirects to the dashboard on success, so there is nothing
        // to do here but report a refusal — a rejected request that left the
        // screen sitting there would read as "it worked".
        router.post(
            freePlan.url(),
            {},
            {
                onError: () =>
                    toast.error(
                        __(
                            'We could not move you to the free plan. Try again.',
                        ),
                    ),
                onFinish: () => setIsLeaving(false),
            },
        );
    };

    const title = banks.length
        ? __('This disconnects your banks')
        : __('This switches the AI off');

    return (
        <SubscriptionLayout>
            <Head title={title} />

            <StepScreen
                title={title}
                description={description(banks, hasAiConsent)}
                footer={
                    <>
                        <StepButton
                            text={
                                banks.length
                                    ? __('Disconnect and continue free')
                                    : __('Switch it off and continue free')
                            }
                            onClick={chooseFreePlan}
                            loading={isLeaving}
                            loadingText={__('Disconnecting…')}
                            data-testid="confirm-free-plan"
                        />
                        <StepButton
                            text={
                                banks.length
                                    ? __('Keep my banks')
                                    : __('Keep it on')
                            }
                            variant="outline"
                            onClick={() => router.visit(subscribe().url)}
                        />
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={Check}
                        title={__('You keep')}
                        description={__(
                            ':count, categories, rules, budgets — all of it',
                            {
                                count:
                                    transactionsCount === 1
                                        ? __('1 movement')
                                        : __(':count movements', {
                                              count: transactionsCount,
                                          }),
                            },
                        )}
                    />
                    <StepRow
                        icon={CircleAlert}
                        title={__('You lose')}
                        description={
                            banks.length
                                ? __(
                                      'Daily sync, AI sorting, and every movement from here on',
                                  )
                                : __('AI sorting, and the assistant with it')
                        }
                    />
                </StepList>

                <StepCallout>
                    <span className="font-medium text-foreground">
                        {__('Nothing is deleted.')}
                    </span>{' '}
                    {__(
                        'Reconnecting later picks up where it left off — you would only be missing the movements from the gap.',
                    )}
                </StepCallout>
            </StepScreen>
        </SubscriptionLayout>
    );
}

/**
 * Named banks rather than a count: "your three BBVA connections" is a thing the
 * user recognises, and recognising it is the point of asking twice.
 */
function description(banks: string[], hasAiConsent: boolean): string {
    if (banks.length === 0) {
        return __(
            'The free plan has no AI sorting, so dropping to it switches it off. Your rules keep running.',
        );
    }

    const named = new Intl.ListFormat(undefined, {
        style: 'long',
        type: 'conjunction',
    }).format(banks);

    return hasAiConsent
        ? __(
              'The free plan has no bank sync, so dropping to it cuts your :banks connections and switches the AI sorting off.',
              { banks: named },
          )
        : __(
              'The free plan has no bank sync, so dropping to it cuts your :banks connections.',
              { banks: named },
          );
}

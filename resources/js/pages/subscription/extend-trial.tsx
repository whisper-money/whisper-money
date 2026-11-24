import { store } from '@/actions/App/Http/Controllers/TrialExtensionController';
import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import { useLocale } from '@/hooks/use-locale';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { billing } from '@/routes/settings';
import { resume } from '@/routes/settings/billing';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { CalendarPlusIcon, CreditCardIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

/** Only reached from the `trial_ending_canceled` email. */
const ANALYTICS = { source: 'email' } as const;

interface TrialExtension {
    /** How many days the trial grows by. */
    days: number;
    /** The day the extended trial would end, and the first charge with it. */
    trialEndsAt: string;
    /** What the first charge would be; null when it cannot be told. */
    plan: Pick<Plan, 'price' | 'billing_period'> | null;
}

interface ExtendTrialPageProps extends SharedData {
    /** Null once the extension no longer applies. */
    extension: TrialExtension | null;
    /** The plan is still set to cancel, so it can be kept as it is. */
    canKeepPlan: boolean;
    /** The plan runs and is not set to cancel: nothing to take back. */
    planIsOn: boolean;
    errors: Record<string, string>;
}

function showFlash(page: { props: unknown }): void {
    const { flash } = page.props as SharedData;

    if (flash?.success) {
        toast.success(flash.success);
    }
}

function firstChargeTerms(
    plan: TrialExtension['plan'],
    currency: string,
    locale: string,
): string {
    if (!plan) {
        return __('Unless you cancel before then.');
    }

    const price = formatCurrency(plan.price * 100, currency, locale);

    return plan.billing_period === 'year'
        ? __(':price a year, unless you cancel before then.', { price })
        : __(':price a month, unless you cancel before then.', { price });
}

function unavailableDescription(
    canKeepPlan: boolean,
    planIsOn: boolean,
): string {
    if (canKeepPlan) {
        return __(
            'Your trial cannot be extended any more, but you can still keep your plan. Your first charge would then be on the day your trial ends.',
        );
    }

    return planIsOn
        ? __(
              'Nothing is set to cancel, so there is nothing to take back. Your billing page shows when your first charge is.',
          )
        : __(
              'Your trial is over, so it can no longer be extended. Your billing page shows where your plan stands.',
          );
}

/**
 * Where the trial-ending email for a cancelled trial leads: two more weeks of
 * trial and the plan back on, confirmed here with one button. Opening the
 * link changes nothing; the button does.
 */
export default function ExtendTrial() {
    const { extension, canKeepPlan, planIsOn, errors, pricing } =
        usePage<ExtendTrialPageProps>().props;
    const locale = useLocale();
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        captureEvent('trial_extension_page_viewed', {
            ...ANALYTICS,
            available: extension !== null,
        });
        // Once per visit: the props only change after a refused POST.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /** POSTs, then reports the event and shows the flash once it landed. */
    const submit = (
        url: string,
        event: string,
        properties: Record<string, unknown>,
    ) => {
        setProcessing(true);
        router.post(
            url,
            {},
            {
                onSuccess: (page) => {
                    captureEvent(event, properties);
                    showFlash(page);
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    const extendTrial = () => submit(store.url(), 'trial_extended', ANALYTICS);

    const keepPlan = () =>
        submit(resume.url(), 'subscription_resumed', {
            ...ANALYTICS,
            on_trial: true,
        });

    const error = errors.trial_extension && (
        <p role="alert" className="text-center text-sm text-destructive">
            {errors.trial_extension}
        </p>
    );

    const seeMyPlan = (
        <StepButton
            text={__('See my plan')}
            variant="ghost"
            onClick={() => router.visit(billing.url())}
        />
    );

    const keepPlanButton = canKeepPlan && (
        <StepButton
            text={__('Keep my plan')}
            variant={extension === null ? 'default' : 'outline'}
            onClick={keepPlan}
            loading={processing}
            disabled={processing}
            data-testid="keep-plan"
        />
    );

    if (extension === null) {
        const title = planIsOn
            ? __('Your plan is already on')
            : __('This offer is no longer available');

        return (
            <SubscriptionLayout withAccountLinks>
                <Head title={title} />

                <StepScreen
                    title={title}
                    description={unavailableDescription(canKeepPlan, planIsOn)}
                    footer={
                        <>
                            {keepPlanButton}
                            {error}
                            {seeMyPlan}
                        </>
                    }
                />
            </SubscriptionLayout>
        );
    }

    const date = formatDateMedium(extension.trialEndsAt, locale);
    const days = String(extension.days);

    return (
        <SubscriptionLayout withAccountLinks>
            <Head title={__(':days more days of free trial', { days })} />

            <StepScreen
                title={__(':days more days of free trial', { days })}
                description={__(
                    'Take more time to see if Standard fits you. Your trial would end on :date, and your plan is back on: you are charged that day unless you cancel before.',
                    { date },
                )}
                footer={
                    <>
                        <StepButton
                            text={__('Extend my trial and keep my plan')}
                            onClick={extendTrial}
                            loading={processing}
                            disabled={processing}
                            data-testid="extend-trial"
                        />
                        {error}
                        {keepPlanButton}
                        {seeMyPlan}
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={CalendarPlusIcon}
                        title={__('Your trial ends on :date', { date })}
                        description={__(
                            'Bank sync, AI categorization and the rest of Standard keep running until then',
                        )}
                    />
                    <StepRow
                        icon={CreditCardIcon}
                        title={__('First charge on :date', { date })}
                        description={firstChargeTerms(
                            extension.plan,
                            pricing.currency,
                            locale,
                        )}
                    />
                </StepList>
            </StepScreen>
        </SubscriptionLayout>
    );
}

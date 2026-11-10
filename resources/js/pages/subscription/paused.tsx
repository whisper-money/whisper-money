import { StepButton } from '@/components/onboarding/step-button';
import { StepList, StepRow } from '@/components/onboarding/step-list';
import { StepScreen } from '@/components/onboarding/step-screen';
import {
    pauseSummary,
    resumeNowTerms,
    type SubscriptionPause,
    useResumePausedPlan,
} from '@/components/subscription/paused-plan-notice';
import { useLocale } from '@/hooks/use-locale';
import SubscriptionLayout from '@/layouts/subscription-layout';
import { captureEvent } from '@/lib/posthog';
import { billing } from '@/routes/settings';
import { type SharedData } from '@/types';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, router, usePage } from '@inertiajs/react';
import { CircleAlert, ShieldCheck } from 'lucide-react';
import { useEffect } from 'react';

interface PausedPageProps extends SharedData {
    pause: SubscriptionPause;
    errors: Record<string, string>;
}

/**
 * The paywall for someone who paused their plan: nothing to buy, only the
 * pause and the way out of it. Every page Standard gates lands here while the
 * pause runs.
 */
export default function Paused() {
    const { pause, pricing, errors } = usePage<PausedPageProps>().props;
    const locale = useLocale();
    const { processing, resumeNow } = useResumePausedPlan(pause, 'paywall');
    const terms = resumeNowTerms(pause, pricing.currency, locale);

    useEffect(() => {
        captureEvent('paywall_viewed', { gate: 'paused' });
    }, []);

    const title = pause.resumesAt
        ? __('Your plan is paused until :date', {
              date: formatDateMedium(pause.resumesAt, locale),
          })
        : __('Your plan is paused');

    return (
        <SubscriptionLayout withAccountLinks>
            <Head title={__('Your plan is paused')} />

            <StepScreen
                title={title}
                description={pauseSummary(pause, pricing.currency, locale)}
                footer={
                    <>
                        <StepButton
                            text={__('Resume now')}
                            onClick={resumeNow}
                            loading={processing}
                            disabled={processing}
                            data-testid="resume-paused-plan"
                        />
                        {terms && (
                            <p className="text-center text-sm text-muted-foreground">
                                {terms}
                            </p>
                        )}
                        {errors.pause && (
                            <p
                                role="alert"
                                className="text-center text-sm text-destructive"
                            >
                                {errors.pause}
                            </p>
                        )}
                        <StepButton
                            text={__('See my plan')}
                            variant="ghost"
                            onClick={() => router.visit(billing.url())}
                        />
                    </>
                }
            >
                <StepList>
                    <StepRow
                        icon={ShieldCheck}
                        title={__('Everything you set up is kept')}
                        description={__(
                            'Accounts, movements, categories and rules',
                        )}
                    />
                    <StepRow
                        icon={CircleAlert}
                        title={__('Bank sync is resting')}
                        description={__(
                            'Your banks stay connected and sync again when the plan resumes',
                        )}
                    />
                </StepList>
            </StepScreen>
        </SubscriptionLayout>
    );
}

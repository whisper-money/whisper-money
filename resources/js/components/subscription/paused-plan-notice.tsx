import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { captureEvent } from '@/lib/posthog';
import { resume } from '@/routes/settings/billing/pause';
import { type SharedData } from '@/types';
import { type Plan } from '@/types/pricing';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { CalendarClockIcon, PauseIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/** `SubscriptionPauseDetails::for()`: a pause running or still to come. */
export interface SubscriptionPause {
    /** Inside the pause, with no plan; false while it is still to come. */
    active: boolean;
    startsAt: string | null;
    /** Null only for a pause with no end, set in Stripe's dashboard. */
    resumesAt: string | null;
    plan: Pick<Plan, 'price' | 'billing_period'> | null;
}

/** Where "Resume now" was pressed, for analytics. */
export type ResumeSource = 'billing' | 'paywall';

/**
 * "Resume now", shared by the billing page and the paused screen. Inside the
 * pause it charges the plan today and brings it back at once; a pause still
 * to come is only dropped.
 */
export function useResumePausedPlan(
    pause: SubscriptionPause,
    source: ResumeSource,
) {
    const [processing, setProcessing] = useState(false);
    const analytics = { source, was_paused: pause.active };

    const resumeNow = () => {
        setProcessing(true);
        router.post(
            resume.url(),
            {},
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const { flash, errors } =
                        page.props as unknown as SharedData & {
                            errors: Record<string, string>;
                        };

                    if (errors?.pause) {
                        return;
                    }

                    captureEvent('subscription_pause_resumed_early', analytics);

                    if (flash?.success) {
                        toast.success(flash.success);
                    }
                },
                onFinish: () => setProcessing(false),
            },
        );
    };

    return { processing, resumeNow };
}

/** The price the plan resumes at, e.g. "€8.99/month". */
export function resumePrice(
    pause: SubscriptionPause,
    currency: string,
    locale: string,
): string | null {
    if (!pause.plan) {
        return null;
    }

    const price = formatCurrency(pause.plan.price * 100, currency, locale);

    return pause.plan.billing_period === 'year'
        ? __(':price a year', { price })
        : __(':price a month', { price });
}

/**
 * What the pause means right now, in one sentence: when it ends and at what
 * price, or, before it starts, how long the plan stays on.
 */
export function pauseSummary(
    pause: SubscriptionPause,
    currency: string,
    locale: string,
): string {
    const price = resumePrice(pause, currency, locale);
    const resumesOn = pause.resumesAt
        ? formatDateMedium(pause.resumesAt, locale)
        : null;

    if (!pause.active && pause.startsAt) {
        const startsOn = formatDateMedium(pause.startsAt, locale);

        return resumesOn && price
            ? __(
                  'Standard stays on until :start. Nothing is charged during the pause, and it resumes on its own on :end at :price.',
                  { start: startsOn, end: resumesOn, price },
              )
            : __(
                  'Standard stays on until :start. Nothing is charged during the pause.',
                  { start: startsOn },
              );
    }

    if (resumesOn && price) {
        return __(
            'It resumes on its own on :date at :price. Until then nothing is charged, bank sync and AI categorization rest, and everything you set up is kept.',
            { date: resumesOn, price },
        );
    }

    return __(
        'Nothing is charged while it is paused, bank sync and AI categorization rest, and everything you set up is kept.',
    );
}

/** What pressing "Resume now" inside the pause costs, said before it is pressed. */
export function resumeNowTerms(
    pause: SubscriptionPause,
    currency: string,
    locale: string,
): string | null {
    if (!pause.active) {
        return null;
    }

    const price = resumePrice(pause, currency, locale);

    if (!price) {
        return __(
            "Resuming now charges your plan's price today, and your plan renews on this day from then on.",
        );
    }

    return __(
        'Resuming now charges :price today, and your plan renews on this day from then on.',
        { price },
    );
}

/**
 * The pause on the billing page: what it is, when it ends, and the one button
 * that takes it back.
 */
export function PausedPlanNotice({
    pause,
    currency,
    locale,
}: {
    pause: SubscriptionPause;
    currency: string;
    locale: string;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const { processing, resumeNow } = useResumePausedPlan(pause, 'billing');
    const terms = resumeNowTerms(pause, currency, locale);

    const title = pause.active
        ? pause.resumesAt
            ? __('Paused until :date', {
                  date: formatDateMedium(pause.resumesAt, locale),
              })
            : __('Your plan is paused')
        : pause.startsAt
          ? __('Your plan pauses on :date', {
                date: formatDateMedium(pause.startsAt, locale),
            })
          : __('Your plan is paused');
    const Icon = pause.active ? PauseIcon : CalendarClockIcon;

    return (
        <Alert data-testid="paused-plan">
            <Icon className="h-4 w-4" />
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription className="flex flex-col items-start gap-3">
                <span>{pauseSummary(pause, currency, locale)}</span>
                {terms && (
                    <span className="text-muted-foreground">{terms}</span>
                )}
                {errors.pause && (
                    <span role="alert" className="text-destructive">
                        {errors.pause}
                    </span>
                )}
                <Button
                    disabled={processing}
                    onClick={resumeNow}
                    data-testid="resume-paused-plan"
                >
                    {processing && <Spinner />}
                    {pause.active ? __('Resume now') : __('Cancel the pause')}
                </Button>
            </AlertDescription>
        </Alert>
    );
}

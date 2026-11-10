import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { captureEvent } from '@/lib/posthog';
import { billing } from '@/routes/settings';
import { resume } from '@/routes/settings/billing';
import { dismiss as dismissTrialBanner } from '@/routes/trial-banner';
import type { TrialEndingBanner as Banner, SharedData } from '@/types';
import { formatCurrency } from '@/utils/currency';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { CalendarClockIcon, XIcon } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

/** "Ends in 5 days", "ends tomorrow", "ends today": the part both variants share. */
function endsIn(daysLeft: number): string {
    if (daysLeft === 0) {
        return __('Your free trial ends today');
    }

    if (daysLeft === 1) {
        return __('Your free trial ends tomorrow');
    }

    return __('Your free trial ends in :days days', {
        days: String(daysLeft),
    });
}

function title(banner: Banner, locale: string, date: string): string {
    if (banner.canceled || banner.charge === null) {
        return endsIn(banner.daysLeft);
    }

    const amount = formatCurrency(
        banner.charge.amount,
        banner.charge.currency,
        locale,
    );
    const charge =
        banner.charge.period === 'year'
            ? __(':amount for the year on :date', { amount, date })
            : __(':amount for the month on :date', { amount, date });

    return `${endsIn(banner.daysLeft)} — ${charge}`;
}

/**
 * What the plan did during the trial, or null when there is too little to
 * say: one synced movement, or none, makes no case, and a 0% is left out.
 */
function progress(banner: Banner, locale: string): string | null {
    const { synced, categorizedPercent } = banner.stats;

    if (synced < 2) {
        return null;
    }

    const count = new Intl.NumberFormat(locale).format(synced);

    return categorizedPercent > 0
        ? __(
              'So far it synced :synced movements and sorted :percent% of them.',
              {
                  synced: count,
                  percent: String(categorizedPercent),
              },
          )
        : __('So far it synced :synced movements.', { synced: count });
}

/**
 * Reminds a trialing user, in the app shell, that the trial is about to end:
 * only 7, 3 and 1 days before, as the server decides, and closing it hides it
 * until the next of those days on every device. It names the first charge and
 * what the plan did so far; a trial already set to cancel gets no charge line,
 * only what stops and the one-click way to keep the plan.
 *
 * The shell shows one banner at most, so this one stands aside for the payment
 * and bank-connection warnings, and for the second offer (which only a user
 * who never paid can get, so the two should never meet). It also stays off the
 * billing page, which shows the trial and its end date itself.
 */
export function TrialEndingBanner() {
    const {
        url,
        props: {
            trialEndingBanner: banner,
            subscriptionPaymentIssue,
            bankConnectionIssues,
            showSecondOffer,
        },
    } = usePage<SharedData>();
    const locale = useLocale();
    const [dismissed, setDismissed] = useState<string | null>(null);
    const [resuming, setResuming] = useState(false);

    const outranked =
        subscriptionPaymentIssue !== null ||
        (bankConnectionIssues ?? []).length > 0 ||
        showSecondOffer;
    const key = banner ? `${banner.trialEndsOn}:${banner.threshold}` : null;

    if (
        !banner ||
        outranked ||
        dismissed === key ||
        url.split('?')[0] === billing.url()
    ) {
        return null;
    }

    const analytics = {
        threshold: banner.threshold,
        canceled: banner.canceled,
    };
    const date = formatDateMedium(banner.trialEndsOn, locale);
    const progressLine = banner.canceled ? null : progress(banner, locale);

    const dismiss = () => {
        captureEvent('trial_banner_dismissed', analytics);
        setDismissed(key);
        // Nothing to do with the answer: if it never lands, the banner simply
        // comes back on the next page load.
        void axios.post(dismissTrialBanner.url()).catch(() => {});
    };

    const keepPlan = () => {
        captureEvent('subscription_resume_clicked', {
            on_trial: true,
            source: 'trial_banner',
        });
        setResuming(true);
        router.post(
            resume.url(),
            {},
            {
                onSuccess: (page) => {
                    const { flash } = page.props as unknown as SharedData;

                    if (flash?.success) {
                        toast.success(flash.success);
                    }
                },
                onFinish: () => setResuming(false),
            },
        );
    };

    return (
        <div className="mx-auto w-full max-w-page px-5 pb-4 sm:px-6">
            <Alert data-testid="trial-ending-banner">
                <CalendarClockIcon />
                <AlertTitle className="line-clamp-none pr-10">
                    {title(banner, locale, date)}
                </AlertTitle>
                <AlertDescription className="flex flex-col items-start gap-2">
                    {banner.canceled ? (
                        <p>
                            {__(
                                "Standard stops on :date and you won't be charged. Your movements stay yours.",
                                { date },
                            )}
                        </p>
                    ) : (
                        <p>
                            {progressLine && <>{progressLine} </>}
                            {__(
                                'Nothing to do to keep it; you can change or cancel the plan any time before.',
                            )}
                        </p>
                    )}
                    {banner.canceled ? (
                        <Button
                            size="sm"
                            disabled={resuming}
                            onClick={keepPlan}
                        >
                            {__('Keep my plan')}
                        </Button>
                    ) : (
                        <Button size="sm" variant="outline" asChild>
                            <Link
                                href={billing.url()}
                                onClick={() =>
                                    captureEvent(
                                        'trial_banner_manage_clicked',
                                        analytics,
                                    )
                                }
                            >
                                {__('Manage plan')}
                            </Link>
                        </Button>
                    )}
                </AlertDescription>
                <Button
                    size="icon"
                    variant="ghost"
                    className="absolute top-0 right-0 size-11 text-muted-foreground hover:text-foreground"
                    onClick={dismiss}
                    aria-label={__('Dismiss')}
                >
                    <XIcon className="size-4" />
                </Button>
            </Alert>
        </div>
    );
}

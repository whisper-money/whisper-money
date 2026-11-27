import { store as snooze } from '@/actions/App/Http/Controllers/ForecastAlertSnoozeController';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { dayOf, listOf, monthLongName } from '@/lib/forecast/format';
import { type ForecastDashboardAlertData } from '@/types/forecast-entry';
import { __ } from '@/utils/i18n';
import { Link, useHttp } from '@inertiajs/react';
import { TriangleAlertIcon } from 'lucide-react';
import { useState } from 'react';

function sentenceCase(text: string): string {
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function useDescription(alert: ForecastDashboardAlertData): string {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();

    if (alert.lowestBalance === null || alert.lowestOn === null) {
        return __('Your balance may not stretch that far that month.');
    }

    const replacements = {
        amount: formatAmount(alert.lowestBalance, alert.currencyCode, {
            fractionDigits: 0,
        }),
        day: dayOf(alert.lowestOn),
        month: monthLongName(alert.month, locale),
    };

    if (alert.causes.length === 0) {
        return __(
            'You could drop to :amount around :day :month.',
            replacements,
        );
    }

    return __(
        'You could drop to :amount around :day :month because of :causes.',
        {
            ...replacements,
            causes: listOf(
                alert.causes.map((cause) => cause.name),
                locale,
            ),
        },
    );
}

/**
 * The dashboard's warning that an account is about to run short, from the
 * forecast's stored alerts: drawn only when there is a strong one. "Remind
 * me" puts it away until the first day of the month before the problem one;
 * the card goes the moment it is clicked and the request goes out on its own,
 * as the monthly summary notice does. Should it fail, the card comes back.
 */
export function ForecastAlertNotice({
    alert,
}: {
    alert: ForecastDashboardAlertData;
}) {
    const locale = useLocale();
    const [snoozed, setSnoozed] = useState(false);
    const { post } = useHttp();
    const description = useDescription(alert);
    const { remindOn } = alert;

    if (snoozed) {
        return null;
    }

    return (
        <Alert
            variant="destructive"
            className="flex flex-col gap-3 border-destructive/30 bg-destructive/5 p-4 sm:p-5"
            data-test="forecast-dashboard-alert"
        >
            <div className="flex items-start gap-2.5">
                <TriangleAlertIcon className="mt-0.5 size-5 shrink-0 text-destructive" />
                <div className="flex flex-col gap-1">
                    <AlertTitle className="text-sm font-semibold text-destructive">
                        {__(':month looks tight in your :account', {
                            month: sentenceCase(
                                monthLongName(alert.month, locale),
                            ),
                            account: alert.accountName,
                        })}
                    </AlertTitle>
                    <AlertDescription className="text-sm text-pretty text-destructive">
                        {description}
                    </AlertDescription>
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button variant="destructive" asChild>
                    <Link
                        href={
                            forecastIndex({
                                query: { account: alert.accountId },
                            }).url
                        }
                    >
                        {__('See forecast')}
                    </Link>
                </Button>
                {remindOn !== null && (
                    <Button
                        variant="outline"
                        className="border-destructive/30 bg-background text-destructive hover:text-destructive"
                        onClick={() => {
                            setSnoozed(true);
                            post(snooze(alert.id).url, {
                                onError: () => setSnoozed(false),
                                onHttpException: () => setSnoozed(false),
                                onNetworkError: () => setSnoozed(false),
                            });
                        }}
                    >
                        {__('Remind me in :month', {
                            month: monthLongName(remindOn.slice(0, 7), locale),
                        })}
                    </Button>
                )}
            </div>
        </Alert>
    );
}

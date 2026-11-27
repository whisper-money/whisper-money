import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { FreePlanBanner } from '@/components/forecast/forecast-alerts';
import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { suggestedTransfer } from '@/components/forecast/forecast-plan-table';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { listOf, monthLongName } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import { type ForecastAllData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    CircleAlertIcon,
    CircleCheckIcon,
    TriangleAlertIcon,
} from 'lucide-react';

/**
 * An account runs short: amber when another one can lend it the money
 * ("the money is not where you need it"), red when none can.
 */
function AccountShortAlert({ forecast }: { forecast: ForecastAllData }) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const editor = useForecastEditor();
    const { alert } = forecast;
    const account = forecast.accountForecasts.find(
        (candidate) => candidate.id === alert.accountId,
    );

    if (account === undefined || alert.month === null || alert.min === null) {
        return null;
    }

    const split = alert.level === 'split';
    const transfer = alert.transfer;
    const lender = forecast.accounts.find(
        (candidate) => candidate.id === transfer?.fromAccountId,
    );
    const values = {
        account: account.name,
        amount: formatAmount(alert.min, account.currencyCode, {
            fractionDigits: 0,
        }),
        month: monthLongName(alert.month, locale),
        lender: lender?.name ?? '',
    };

    return (
        <Alert
            variant={split ? 'warning' : 'destructive'}
            className={cn(
                'flex flex-wrap items-center justify-between gap-4 px-4 py-4',
                !split && 'border-destructive/30 bg-destructive/5',
            )}
            data-test={`forecast-all-alert-${alert.level}`}
        >
            <div className="flex items-start gap-3">
                <TriangleAlertIcon className="mt-0.5 size-5 shrink-0" />
                <div className="flex flex-col gap-1">
                    <AlertTitle className="text-sm font-semibold">
                        {split
                            ? __(
                                  'Together you are doing fine, but the money is not where you need it',
                              )
                            : __(
                                  'Together your accounts do not cover :month',
                                  values,
                              )}
                    </AlertTitle>
                    <AlertDescription>
                        {split && lender !== undefined
                            ? __(
                                  'Your total holds in :month, but :account drops to :amount. You have enough in :lender to cover it with a transfer.',
                                  values,
                              )
                            : split
                              ? __(
                                    'Your total holds in :month, but :account drops to :amount. Your other accounts have enough to cover it.',
                                    values,
                                )
                              : __(
                                    ':account drops to :amount in :month and no other account has enough to cover it.',
                                    values,
                                )}
                    </AlertDescription>
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    asChild
                    variant="outline"
                    className={cn(
                        'bg-background',
                        split
                            ? 'border-warning/30 text-warning hover:text-warning'
                            : 'border-destructive/30 text-destructive hover:text-destructive',
                    )}
                >
                    <Link
                        href={forecastIndex({
                            query: {
                                account: account.id,
                                horizon: forecast.horizon,
                            },
                        })}
                        data-test="forecast-all-see-account"
                    >
                        {__('See :account', values)}
                    </Link>
                </Button>
                {split && transfer !== null && editor !== null && (
                    <Button
                        className="bg-warning text-warning-foreground hover:bg-warning/90"
                        onClick={() =>
                            editor.open(suggestedTransfer(transfer, account.id))
                        }
                        data-test="forecast-all-plan-transfer"
                    >
                        {__('Plan a transfer')}
                    </Button>
                )}
            </div>
        </Alert>
    );
}

function SoftAlert({ forecast }: { forecast: ForecastAllData }) {
    const locale = useLocale();
    const months = listOf(
        forecast.alert.softMonths.map((month) => monthLongName(month, locale)),
        locale,
    );

    return (
        <Alert
            variant="warning"
            role="status"
            className="px-4"
            data-test="forecast-all-alert-soft"
        >
            <CircleAlertIcon />
            <AlertDescription className="text-sm">
                {__(
                    'In :months an account is cutting it close: if you spend like in your worst months, it could go below its minimum.',
                    { months },
                )}
            </AlertDescription>
        </Alert>
    );
}

/**
 * The warnings of the "All accounts" view: the free plan's upsell, then an
 * account that runs short, one that only might, or that every account
 * holds. Without Pro only the visible month is read, so "they hold" is
 * never said there.
 */
export function ForecastAllAlerts({ forecast }: { forecast: ForecastAllData }) {
    const { alert, isPro, lockedAlertCount } = forecast;

    return (
        <>
            {forecast.unconvertedCurrencies.length > 0 && (
                <Alert
                    role="status"
                    className="px-4"
                    data-test="forecast-all-unconverted"
                >
                    <CircleAlertIcon />
                    <AlertDescription className="text-sm">
                        {__(
                            'There is no exchange rate for :currencies today, so those accounts are left out of the total.',
                            {
                                currencies:
                                    forecast.unconvertedCurrencies.join(', '),
                            },
                        )}
                    </AlertDescription>
                </Alert>
            )}
            {!isPro && (
                <FreePlanBanner lockedAlertCount={lockedAlertCount ?? 0} />
            )}
            {(alert.level === 'split' || alert.level === 'short') && (
                <AccountShortAlert forecast={forecast} />
            )}
            {alert.level === 'soft' && <SoftAlert forecast={forecast} />}
            {isPro && alert.level === 'ok' && (
                <Alert
                    role="status"
                    className="border-success/30 bg-success/5 px-4 text-success"
                    data-test="forecast-all-alert-ok"
                >
                    <CircleCheckIcon />
                    <AlertDescription className="text-sm text-success">
                        {__(
                            'All your accounts stay above their minimum for the next :count months.',
                            { count: forecast.horizon },
                        )}
                    </AlertDescription>
                </Alert>
            )}
        </>
    );
}

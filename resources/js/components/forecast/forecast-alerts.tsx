import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { suggestedTransfer } from '@/components/forecast/forecast-plan-table';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { dayOf, listOf, monthLongName } from '@/lib/forecast/format';
import { type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import {
    CircleAlertIcon,
    CircleCheckIcon,
    LockIcon,
    TriangleAlertIcon,
} from 'lucide-react';
import { useState } from 'react';

interface ForecastAlertsProps {
    forecast: ForecastData;
    onShowMonth: (month: string) => void;
}

function lockedTitle(count: number): string {
    if (count === 0) {
        return __('See the next 12 months with Pro');
    }

    return count === 1
        ? __('You have 1 warning further ahead')
        : __('You have :count warnings further ahead', { count });
}

export function FreePlanBanner({
    lockedAlertCount,
}: {
    lockedAlertCount: number;
}) {
    const [open, setOpen] = useState(false);

    return (
        <>
            <Alert
                role="status"
                className="flex flex-wrap items-center justify-between gap-4 bg-muted/50 px-4 py-4"
                data-test="forecast-free-banner"
            >
                <div className="flex items-start gap-3">
                    <LockIcon className="mt-0.5 size-5 shrink-0" />
                    <div className="flex flex-col gap-1">
                        <strong className="text-sm font-semibold">
                            {lockedTitle(lockedAlertCount)}
                        </strong>
                        <span className="text-sm text-muted-foreground">
                            {__(
                                'On the free plan you see next month. With Pro you see 12 months, we warn you before an account runs short and you can edit your plan.',
                            )}
                        </span>
                    </div>
                </div>
                <Button onClick={() => setOpen(true)}>
                    {__('Unlock 12 months with Pro')}
                </Button>
            </Alert>
            <UpgradeDialog
                open={open}
                onOpenChange={setOpen}
                source="forecast"
            />
        </>
    );
}

function StrongAlert({ forecast, onShowMonth }: ForecastAlertsProps) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { alert, currencyCode } = forecast;

    if (alert.month === null || alert.min === null) {
        return null;
    }

    const month = monthLongName(alert.month, locale);
    const causes = alert.causes.map(
        (cause) =>
            `${cause.name} (${formatAmount(cause.amount, currencyCode, { fractionDigits: 0 })})`,
    );

    return (
        <Alert
            variant="destructive"
            className="flex flex-wrap items-center justify-between gap-4 border-destructive/30 bg-destructive/5 px-4 py-4"
            data-test="forecast-alert-strong"
        >
            <div className="flex items-start gap-3">
                <TriangleAlertIcon className="mt-0.5 size-5 shrink-0" />
                <div className="flex flex-col gap-1">
                    <AlertTitle className="text-sm font-semibold">
                        {alert.minDate
                            ? __(
                                  'You will drop to :amount around :day :month',
                                  {
                                      amount: formatAmount(
                                          alert.min,
                                          currencyCode,
                                          {
                                              fractionDigits: 0,
                                          },
                                      ),
                                      day: dayOf(alert.minDate),
                                      month,
                                  },
                              )
                            : __('You will drop to :amount in :month', {
                                  amount: formatAmount(
                                      alert.min,
                                      currencyCode,
                                      {
                                          fractionDigits: 0,
                                      },
                                  ),
                                  month,
                              })}
                    </AlertTitle>
                    <AlertDescription>
                        {causes.length > 0
                            ? __(
                                  ':causes land that month. This is what you already know you will pay, not an estimate.',
                                  { causes: listOf(causes, locale) },
                              )
                            : __(
                                  'What you know you will pay and what you usually spend do not fit in the balance that month.',
                              )}
                    </AlertDescription>
                </div>
            </div>
            <div className="flex flex-wrap gap-2">
                <Button
                    variant="outline"
                    className="border-destructive/30 bg-background text-destructive hover:text-destructive"
                    onClick={() => onShowMonth(alert.month as string)}
                >
                    {__('See :month in the plan', { month })}
                </Button>
                <PlanTransferButton forecast={forecast} />
            </div>
        </Alert>
    );
}

/** "Planificar traspaso de 500 € desde Ahorro": the suggested transfer, prefilled. */
function PlanTransferButton({ forecast }: { forecast: ForecastData }) {
    const editor = useForecastEditor();
    const { formatAmount } = useFormatAmount();
    const transfer = forecast.alert.transfer;
    const from = forecast.accounts.find(
        (account) => account.id === transfer?.fromAccountId,
    );

    if (transfer === null || from === undefined || editor === null) {
        return null;
    }

    return (
        <Button
            variant="destructive"
            onClick={() =>
                editor.open(suggestedTransfer(transfer, forecast.account.id))
            }
            data-test="forecast-plan-transfer"
        >
            {__('Plan a transfer of :amount from :account', {
                amount: formatAmount(transfer.amount, forecast.currencyCode, {
                    fractionDigits: 0,
                }),
                account: from.name,
            })}
        </Button>
    );
}

function SoftAlert({ forecast }: { forecast: ForecastData }) {
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
            data-test="forecast-alert-soft"
        >
            <CircleAlertIcon />
            <AlertDescription className="text-sm">
                {__(
                    'In :months you are cutting it close: if you spend like in your worst months, the account could go below zero before you get paid.',
                    { months },
                )}
            </AlertDescription>
        </Alert>
    );
}

function AllGood({ forecast }: { forecast: ForecastData }) {
    return (
        <Alert
            role="status"
            className="border-success/30 bg-success/5 px-4 text-success"
            data-test="forecast-alert-ok"
        >
            <CircleCheckIcon />
            <AlertDescription className="text-sm text-success">
                {__(
                    'Your :account holds up for the next :count months, even if you spend like in your worst months.',
                    { account: forecast.account.name, count: forecast.horizon },
                )}
            </AlertDescription>
        </Alert>
    );
}

/**
 * The warnings above the forecast: the free plan's upsell, then a strong
 * problem (the balance falls below zero, or below the wanted minimum), a
 * soft one (only in the worst case), or that the account holds up. Without
 * Pro only the visible month is read, so "it holds up" is never said there.
 */
export function ForecastAlerts({ forecast, onShowMonth }: ForecastAlertsProps) {
    const { alert, isPro, lockedAlertCount } = forecast;

    return (
        <>
            {!isPro && (
                <FreePlanBanner lockedAlertCount={lockedAlertCount ?? 0} />
            )}
            {alert.level === 'strong' && (
                <StrongAlert forecast={forecast} onShowMonth={onShowMonth} />
            )}
            {alert.softMonths.length > 0 && <SoftAlert forecast={forecast} />}
            {isPro && alert.level === 'ok' && <AllGood forecast={forecast} />}
        </>
    );
}

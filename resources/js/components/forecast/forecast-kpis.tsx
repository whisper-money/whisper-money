import { AmountDisplay } from '@/components/ui/amount-display';
import { Card, CardContent } from '@/components/ui/card';
import {
    StatTile,
    StatTileDescription,
    StatTileLabel,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import {
    balanceTone,
    dayOf,
    monthLongName,
    monthWithYear,
} from '@/lib/forecast/format';
import { type ForecastData } from '@/types/forecast';
import { __ } from '@/utils/i18n';

function KpiCard({ children }: { children: React.ReactNode }) {
    return (
        <Card className="py-4">
            <CardContent className="px-4">
                <StatTile size="lg">{children}</StatTile>
            </CardContent>
        </Card>
    );
}

/**
 * The four figures of the forecast: today's balance, where the horizon ends
 * and its likely range, the lowest point, and how much of the spend is
 * concrete rather than estimated.
 */
export function ForecastKpis({ forecast }: { forecast: ForecastData }) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { kpis, currencyCode, account } = forecast;
    const share = kpis.concreteShare ?? 0;
    const format = (amount: number) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });

    return (
        <div
            className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
            data-test="forecast-kpis"
        >
            <KpiCard>
                <StatTileLabel>{__('Balance today')}</StatTileLabel>
                <StatTileValue>
                    <AmountDisplay
                        amountInCents={kpis.balanceToday}
                        currencyCode={currencyCode}
                        maximumFractionDigits={0}
                        minimumFractionDigits={0}
                    />
                </StatTileValue>
                <StatTileDescription>
                    {account.minimumBalance === null
                        ? __('No wanted minimum')
                        : __('Wanted minimum :amount', {
                              amount: format(account.minimumBalance),
                          })}
                </StatTileDescription>
            </KpiCard>
            <KpiCard>
                <StatTileLabel>
                    {__('At the end of :month', {
                        month: monthWithYear(kpis.end.month, locale),
                    })}
                </StatTileLabel>
                <StatTileValue>
                    <AmountDisplay
                        amountInCents={kpis.end.close}
                        currencyCode={currencyCode}
                        maximumFractionDigits={0}
                        minimumFractionDigits={0}
                    />
                </StatTileValue>
                <StatTileDescription>
                    {__('likely between :low and :high', {
                        low: format(kpis.end.close - kpis.end.band),
                        high: format(kpis.end.close + kpis.end.band),
                    })}
                </StatTileDescription>
            </KpiCard>
            <KpiCard>
                <StatTileLabel>{__('Lowest point')}</StatTileLabel>
                <StatTileValue
                    className={balanceTone(
                        kpis.lowest.min,
                        account.minimumBalance,
                    )}
                >
                    <AmountDisplay
                        amountInCents={kpis.lowest.min}
                        currencyCode={currencyCode}
                        maximumFractionDigits={0}
                        minimumFractionDigits={0}
                    />
                </StatTileValue>
                <StatTileDescription>
                    {kpis.lowest.minDate
                        ? __('around :day :month', {
                              day: dayOf(kpis.lowest.minDate),
                              month: monthLongName(kpis.lowest.month, locale),
                          })
                        : monthLongName(kpis.lowest.month, locale)}
                </StatTileDescription>
            </KpiCard>
            <KpiCard>
                <StatTileLabel>{__('How much is certain')}</StatTileLabel>
                <StatTileValue>
                    {kpis.concreteShare === null
                        ? '—'
                        : __(':percent% concrete', { percent: share })}
                </StatTileValue>
                <div
                    aria-hidden="true"
                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                >
                    <div
                        className="h-1.5 rounded-full bg-primary"
                        style={{ width: `${share}%` }}
                    />
                </div>
                <StatTileDescription>
                    {__('Add your yearly payments to sharpen it')}
                </StatTileDescription>
            </KpiCard>
        </div>
    );
}

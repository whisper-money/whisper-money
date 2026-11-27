import { useForecastEditor } from '@/components/forecast/forecast-editor';
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
import {
    type ForecastAllData,
    type ForecastData,
    type ForecastKpis as ForecastKpisData,
} from '@/types/forecast';
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

/** "Wanted minimum 300 € · change": the account's threshold, and the way to its settings. */
export function WantedMinimum({ forecast }: { forecast: ForecastData }) {
    const { formatAmount } = useFormatAmount();
    const editor = useForecastEditor();
    const { minimumBalance } = forecast.account;

    return (
        <>
            {minimumBalance === null
                ? __('No wanted minimum')
                : __('Wanted minimum :amount', {
                      amount: formatAmount(
                          minimumBalance,
                          forecast.currencyCode,
                          { fractionDigits: 0 },
                      ),
                  })}
            {editor && (
                <>
                    {' · '}
                    <button
                        type="button"
                        className="underline underline-offset-4 hover:text-foreground"
                        onClick={() => editor.open({ kind: 'account' })}
                        data-test="forecast-change-minimum"
                    >
                        {__('change')}
                    </button>
                </>
            )}
        </>
    );
}

/** Where the horizon ends, and its likely range. */
function EndKpi({
    end,
    currencyCode,
}: {
    end: ForecastKpisData['end'];
    currencyCode: string;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const format = (amount: number) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });

    return (
        <KpiCard>
            <StatTileLabel>
                {__('At the end of :month', {
                    month: monthWithYear(end.month, locale),
                })}
            </StatTileLabel>
            <StatTileValue>
                <WholeAmount amount={end.close} currencyCode={currencyCode} />
            </StatTileValue>
            <StatTileDescription>
                {__('likely between :low and :high', {
                    low: format(end.close - end.band),
                    high: format(end.close + end.band),
                })}
            </StatTileDescription>
        </KpiCard>
    );
}

/**
 * The four figures of the forecast: today's balance, where the horizon ends
 * and its likely range, the lowest point, and how much of the spend is
 * concrete rather than estimated.
 */
export function ForecastKpis({
    forecast,
    onAddYearly,
}: {
    forecast: ForecastData;
    /** Opens the review on its yearly payments. */
    onAddYearly?: () => void;
}) {
    const locale = useLocale();
    const { kpis, currencyCode, account } = forecast;
    const share = kpis.concreteShare ?? 0;

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
                    <WantedMinimum forecast={forecast} />
                </StatTileDescription>
            </KpiCard>
            <EndKpi end={kpis.end} currencyCode={currencyCode} />
            <KpiCard>
                <StatTileLabel>{__('Lowest point')}</StatTileLabel>
                <StatTileValue
                    className={balanceTone(
                        kpis.lowest.min,
                        account.alertThreshold,
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
                    {onAddYearly ? (
                        <button
                            type="button"
                            className="text-left text-foreground underline underline-offset-4 hover:text-foreground/80"
                            onClick={onAddYearly}
                            data-test="forecast-add-yearly"
                        >
                            {__('Add your yearly payments to sharpen it')}
                        </button>
                    ) : (
                        __('Add your yearly payments to sharpen it')
                    )}
                </StatTileDescription>
            </KpiCard>
        </div>
    );
}

function WholeAmount({
    amount,
    currencyCode,
}: {
    amount: number;
    currencyCode: string;
}) {
    return (
        <AmountDisplay
            amountInCents={amount}
            currencyCode={currencyCode}
            maximumFractionDigits={0}
            minimumFractionDigits={0}
        />
    );
}

/**
 * The four figures of the "All accounts" view: what the accounts hold
 * today, where the total ends and its likely range, where its rise comes
 * from (what is saved and what the investments grow), and what moves
 * between the accounts without changing the total.
 */
export function ForecastAllKpis({ forecast }: { forecast: ForecastAllData }) {
    const { formatAmount } = useFormatAmount();
    const { kpis, currencyCode } = forecast;
    const format = (amount: number, sign = false) =>
        `${sign && amount > 0 ? '+' : ''}${formatAmount(amount, currencyCode, { fractionDigits: 0 })}`;

    return (
        <div
            className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4"
            data-test="forecast-kpis"
        >
            <KpiCard>
                <StatTileLabel>
                    {kpis.accountCount === 1
                        ? __('Balance today · 1 account')
                        : __('Balance today · :count accounts', {
                              count: kpis.accountCount,
                          })}
                </StatTileLabel>
                <StatTileValue>
                    <WholeAmount
                        amount={kpis.balanceToday}
                        currencyCode={currencyCode}
                    />
                </StatTileValue>
                <StatTileDescription>
                    {__('The last known balance of each one')}
                </StatTileDescription>
            </KpiCard>
            <EndKpi end={kpis.end} currencyCode={currencyCode} />
            <KpiCard>
                <StatTileLabel>
                    {kpis.saved + kpis.growth < 0
                        ? __('Where the drop comes from')
                        : __('Where the rise comes from')}
                </StatTileLabel>
                <StatTileValue
                    className={kpis.saved > 0 ? 'text-success' : undefined}
                >
                    {format(kpis.saved, true)}
                </StatTileValue>
                <StatTileDescription>
                    {__('saved · :growth from growth', {
                        growth: format(kpis.growth, true),
                    })}
                </StatTileDescription>
            </KpiCard>
            <KpiCard>
                <StatTileLabel>
                    {__('Moved between your accounts')}
                </StatTileLabel>
                <StatTileValue>
                    <WholeAmount
                        amount={kpis.moved}
                        currencyCode={currencyCode}
                    />
                </StatTileValue>
                <StatTileDescription>
                    {__('It neither adds to nor takes from your total')}
                </StatTileDescription>
            </KpiCard>
        </div>
    );
}

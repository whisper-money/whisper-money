import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { ForecastAlerts } from '@/components/forecast/forecast-alerts';
import { ForecastAllAlerts } from '@/components/forecast/forecast-all-alerts';
import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { WantedMinimum } from '@/components/forecast/forecast-kpis';
import { ForecastMobileChart } from '@/components/forecast/forecast-mobile-chart';
import {
    ForecastMonthCard,
    ForecastMonthChips,
    LockedMonthCard,
} from '@/components/forecast/forecast-mobile-month';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    StatTile,
    StatTileDescription,
    StatTileLabel,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { shortAccountCount } from '@/lib/forecast/all-accounts';
import { isShort, monthWithYear, trendLabel } from '@/lib/forecast/format';
import {
    accountMonthGroups,
    allMonthGroups,
} from '@/lib/forecast/mobile-month';
import { initiallyExpandedGroups } from '@/lib/forecast/plan-rows';
import { cn } from '@/lib/utils';
import {
    type ForecastAccountTab,
    type ForecastAllData,
    type ForecastData,
    type ForecastHorizon,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { PlusIcon, Settings2Icon } from 'lucide-react';
import { useId, useMemo, type ReactNode } from 'react';

const HORIZONS: ForecastHorizon[] = [12, 6, 3];

const SELECT =
    'min-h-10 rounded-md border border-input bg-background px-2.5 text-sm text-foreground shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30';

/** The header's round "+", which opens the add sheet once there is an account. */
export function ForecastMobileAddButton() {
    const editor = useForecastEditor();

    return (
        <Button
            size="icon"
            className="size-10 rounded-full"
            disabled={editor === null}
            onClick={() => editor?.open({ kind: 'add' })}
            aria-label={__('Add to the forecast')}
            data-test="forecast-add"
        >
            <PlusIcon className="size-[18px]" strokeWidth={2.5} />
        </Button>
    );
}

/** An account as the phone's picker names it: a warning, "projection only" or its balance trend after it. */
function mobileAccountLabel(
    account: ForecastAccountTab,
    format: (amount: number, currencyCode: string) => string,
): string {
    if (account.hasAlert) {
        return `${account.name} · ⚠`;
    }

    const trend = trendLabel(
        account.trend,
        (amount) => format(amount, account.currencyCode),
        true,
    );

    return [
        account.name,
        account.projectionOnly ? __('projection only') : null,
        trend,
    ]
        .filter((part) => part !== null)
        .join(' · ');
}

/** The phone's account and horizon pickers, as the system's own selects, and the way to choose the accounts. */
function MobileControls({
    forecast,
}: {
    forecast: ForecastData | ForecastAllData;
}) {
    const id = useId();
    const editor = useForecastEditor();
    const { formatAmount } = useFormatAmount();
    const format = (amount: number, currencyCode: string) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });
    const accountId = forecast.scope === 'all' ? 'all' : forecast.account.id;
    const visit = (account: string, horizon: ForecastHorizon) =>
        router.visit(forecastIndex({ query: { account, horizon } }).url, {
            preserveScroll: true,
        });

    return (
        <div className="flex gap-2">
            <label htmlFor={`${id}-account`} className="sr-only">
                {__('Account')}
            </label>
            <select
                id={`${id}-account`}
                className={cn(SELECT, 'min-w-0 flex-1')}
                value={accountId}
                onChange={(event) =>
                    visit(event.target.value, forecast.horizon)
                }
                data-test="forecast-mobile-account"
            >
                <option value="all">{__('All accounts')}</option>
                {forecast.accounts.map((account) => (
                    <option key={account.id} value={account.id}>
                        {mobileAccountLabel(account, format)}
                    </option>
                ))}
            </select>
            <label htmlFor={`${id}-horizon`} className="sr-only">
                {__('Horizon')}
            </label>
            <select
                id={`${id}-horizon`}
                className={cn(SELECT, 'w-[118px]')}
                value={forecast.horizon}
                onChange={(event) =>
                    visit(
                        accountId,
                        Number(event.target.value) as ForecastHorizon,
                    )
                }
                data-test="forecast-mobile-horizon"
            >
                {HORIZONS.map((horizon) => (
                    <option key={horizon} value={horizon}>
                        {__(':count months', { count: horizon })}
                    </option>
                ))}
            </select>
            {editor && (
                <Button
                    variant="outline"
                    size="icon"
                    className="size-10 shrink-0"
                    onClick={() => editor.open({ kind: 'accounts' })}
                    aria-label={__('Choose accounts')}
                    data-test="forecast-choose-accounts"
                >
                    <Settings2Icon />
                </Button>
            )}
        </div>
    );
}

/** Today's balance, and where the horizon ends with its likely range. */
function MobileKpis({
    forecast,
}: {
    forecast: ForecastData | ForecastAllData;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const { kpis, currencyCode } = forecast;
    const format = (amount: number) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });
    const amount = (value: number) => (
        <AmountDisplay
            amountInCents={value}
            currencyCode={currencyCode}
            maximumFractionDigits={0}
            minimumFractionDigits={0}
        />
    );

    return (
        <div className="grid grid-cols-2 gap-2" data-test="forecast-kpis">
            <Card size="sm" className="py-3">
                <CardContent className="px-3">
                    <StatTile size="md">
                        <StatTileLabel>{__('Balance today')}</StatTileLabel>
                        <StatTileValue>
                            {amount(kpis.balanceToday)}
                        </StatTileValue>
                        {forecast.scope === 'account' && (
                            <StatTileDescription className="text-[11px]">
                                <WantedMinimum forecast={forecast} />
                            </StatTileDescription>
                        )}
                    </StatTile>
                </CardContent>
            </Card>
            <Card size="sm" className="py-3">
                <CardContent className="px-3">
                    <StatTile size="md">
                        <StatTileLabel className="first-letter:uppercase">
                            {monthWithYear(kpis.end.month, locale)}
                        </StatTileLabel>
                        <StatTileValue>{amount(kpis.end.close)}</StatTileValue>
                        <StatTileDescription className="text-[11px]">
                            {__('likely between :low and :high', {
                                low: format(kpis.end.close - kpis.end.band),
                                high: format(kpis.end.close + kpis.end.band),
                            })}
                        </StatTileDescription>
                    </StatTile>
                </CardContent>
            </Card>
        </div>
    );
}

/** What the bars and the chips read against: the account's threshold, zero for the total. */
function thresholdOf(forecast: ForecastData | ForecastAllData): number | null {
    return forecast.scope === 'all' ? 0 : forecast.account.alertThreshold;
}

interface ForecastMobileViewProps {
    forecast: ForecastData | ForecastAllData;
    selectedMonth: string | null;
    onSelectMonth: (month: string) => void;
    /** Under the month chips: the review's link, when there is something new. */
    reviewAction?: ReactNode;
}

/**
 * The forecast on a phone: instead of the plan's twelve columns, the
 * account and horizon pickers, the warning, two figures, a compact chart and
 * one month at a time, picked from a row of chips or by tapping the chart.
 */
export function ForecastMobileView({
    forecast,
    selectedMonth,
    onSelectMonth,
    reviewAction,
}: ForecastMobileViewProps) {
    const threshold = thresholdOf(forecast);
    const projectionByMonth = useMemo(
        () =>
            Object.fromEntries(
                forecast.projection.map((month) => [month.month, month]),
            ),
        [forecast.projection],
    );
    const column = forecast.months.find(
        (candidate) => candidate.month === selectedMonth,
    );
    const groups = useMemo(() => {
        if (column === undefined || column.status === 'locked') {
            return [];
        }

        return forecast.scope === 'all'
            ? allMonthGroups(forecast, column)
            : accountMonthGroups(forecast, column);
    }, [forecast, column]);
    const isShortMonth = (month: string) => {
        const projected = projectionByMonth[month];

        if (projected === undefined) {
            return false;
        }

        return forecast.scope === 'all'
            ? shortAccountCount(forecast, month) > 0 ||
                  isShort(projected.min, threshold)
            : isShort(projected.min, threshold);
    };

    return (
        <div className="flex flex-col gap-3.5" data-test="forecast-mobile">
            <MobileControls forecast={forecast} />
            {forecast.scope === 'all' ? (
                <ForecastAllAlerts forecast={forecast} />
            ) : (
                <ForecastAlerts
                    forecast={forecast}
                    onShowMonth={onSelectMonth}
                    compact
                />
            )}
            <MobileKpis forecast={forecast} />
            <ForecastMobileChart
                forecast={forecast}
                threshold={threshold}
                selectedMonth={selectedMonth}
                onSelectMonth={onSelectMonth}
            />
            <ForecastMonthChips
                months={forecast.months}
                selectedMonth={selectedMonth}
                isShortMonth={isShortMonth}
                onSelectMonth={onSelectMonth}
            />
            {reviewAction}
            {column?.status === 'locked' ? (
                <LockedMonthCard month={column.month} />
            ) : (
                column && (
                    <ForecastMonthCard
                        key={column.month}
                        month={column.month}
                        projected={projectionByMonth[column.month]}
                        threshold={threshold}
                        currencyCode={forecast.currencyCode}
                        groups={groups}
                        initiallyOpen={
                            forecast.scope === 'all'
                                ? []
                                : initiallyExpandedGroups(
                                      forecast.plan,
                                      column.month,
                                  )
                        }
                    />
                )
            )}
        </div>
    );
}

import { AmountTrendIndicator } from '@/components/dashboard/amount-trend-indicator';
import { PercentageTrendIndicator } from '@/components/dashboard/percentage-trend-indicator';
import {
    Specimen,
    type PreviewEntry,
} from '@/components/design-system/showcase';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { type ChartConfig } from '@/components/ui/chart';
import { StackedAreaChart } from '@/components/ui/stacked-area-chart';
import { StackedBarChart } from '@/components/ui/stacked-bar-chart';
import {
    StatTile,
    StatTileDescription,
    StatTileHeader,
    StatTileLabel,
    StatTileSkeleton,
    StatTileTrend,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { Wallet } from 'lucide-react';

/** The string form, for a sentence or an aria-label; privacy mode masks it too. */
function FormattedAmountSentence() {
    const { formatAmount } = useFormatAmount();

    return (
        <p className="text-sm">
            Still to share out: {formatAmount(4250, 'EUR')}
        </p>
    );
}

function Amounts() {
    return (
        <>
            <Specimen label="income · text-success, showSign">
                <AmountDisplay
                    amountInCents={245000}
                    currencyCode="EUR"
                    showSign
                    className="text-success"
                    weight="medium"
                />
            </Specimen>
            <Specimen label="expense · foreground">
                <AmountDisplay
                    amountInCents={-8642}
                    currencyCode="EUR"
                    weight="medium"
                />
            </Specimen>
            <Specimen label="variant=large">
                <AmountDisplay
                    amountInCents={1234567}
                    currencyCode="EUR"
                    variant="large"
                />
            </Specimen>
            <Specimen label="variant=compact · USD">
                <AmountDisplay
                    amountInCents={99900}
                    currencyCode="USD"
                    variant="compact"
                />
            </Specimen>
            <Specimen label="monospace · JPY">
                <AmountDisplay
                    amountInCents={128000}
                    currencyCode="JPY"
                    monospace
                />
            </Specimen>
            <Specimen label="useFormatAmount() · a string, masked too">
                <FormattedAmountSentence />
            </Specimen>
        </>
    );
}

function TrendIndicators() {
    return (
        <>
            <Specimen label="PercentageTrendIndicator · favourable">
                <PercentageTrendIndicator
                    trend={12.4}
                    label="vs last month"
                    previousAmount={180000}
                    currentAmount={202320}
                    currencyCode="EUR"
                />
            </Specimen>
            <Specimen label="PercentageTrendIndicator · invertColors">
                <PercentageTrendIndicator
                    trend={8.1}
                    label="spending"
                    invertColors
                />
            </Specimen>
            <Specimen label="AmountTrendIndicator">
                <AmountTrendIndicator
                    trend={42000}
                    isPositive
                    label="this month"
                    currencyCode="EUR"
                />
            </Specimen>
        </>
    );
}

function StatTiles() {
    return (
        <div className="grid w-full gap-6 lg:grid-cols-2">
            <Specimen label="size=lg · value, trend, description">
                <StatTile size="lg">
                    <StatTileHeader>
                        <StatTileLabel>Brokerage</StatTileLabel>
                        <Wallet className="size-4 text-muted-foreground" />
                    </StatTileHeader>
                    <StatTileValue>
                        <AmountDisplay
                            amountInCents={12450000}
                            currencyCode="EUR"
                        />
                    </StatTileValue>
                    <StatTileTrend>
                        <AmountTrendIndicator
                            trend={22815}
                            isPositive
                            label="vs last month"
                            currencyCode="EUR"
                        />
                    </StatTileTrend>
                    <StatTileDescription>
                        +12.4% of invested
                    </StatTileDescription>
                </StatTile>
            </Specimen>
            <Specimen label="size=lg · percentage, unfavourable trend">
                <StatTile size="lg">
                    <StatTileLabel>Savings rate</StatTileLabel>
                    <StatTileValue>18.2%</StatTileValue>
                    <StatTileTrend>
                        <PercentageTrendIndicator
                            trend={-3.1}
                            label="vs last month"
                        />
                    </StatTileTrend>
                </StatTile>
            </Specimen>
            <Specimen label="size=sm · a row of figures in a card">
                <div className="grid grid-cols-3 gap-4">
                    <StatTile size="sm">
                        <StatTileLabel>Income</StatTileLabel>
                        <StatTileValue className="text-success">
                            <AmountDisplay
                                amountInCents={511600}
                                currencyCode="EUR"
                                showSign
                            />
                        </StatTileValue>
                    </StatTile>
                    <StatTile size="sm">
                        <StatTileLabel>Expenses</StatTileLabel>
                        <StatTileValue>
                            <AmountDisplay
                                amountInCents={-391350}
                                currencyCode="EUR"
                            />
                        </StatTileValue>
                    </StatTile>
                    <StatTile size="sm">
                        <StatTileHeader>
                            <StatTileLabel>Saved</StatTileLabel>
                            <Badge variant="success">23%</Badge>
                        </StatTileHeader>
                        <StatTileValue>
                            <AmountDisplay
                                amountInCents={120250}
                                currencyCode="EUR"
                            />
                        </StatTileValue>
                    </StatTile>
                </div>
            </Specimen>
            <Specimen label="variant=muted · a grid of tiles">
                <div className="grid grid-cols-2 gap-3">
                    <StatTile variant="muted">
                        <StatTileLabel>Available</StatTileLabel>
                        <StatTileValue>
                            <AmountDisplay
                                amountInCents={198250}
                                currencyCode="EUR"
                            />
                        </StatTileValue>
                    </StatTile>
                    <StatTile variant="muted">
                        <StatTileLabel>Free</StatTileLabel>
                        <StatTileValue>
                            <AmountDisplay
                                amountInCents={84000}
                                currencyCode="EUR"
                            />
                        </StatTileValue>
                        <StatTileDescription>
                            <AmountDisplay
                                amountInCents={114250}
                                currencyCode="EUR"
                            />{' '}
                            reserved
                        </StatTileDescription>
                    </StatTile>
                </div>
            </Specimen>
            <Specimen label="StatTileSkeleton · sm, md, lg withTrend">
                <div className="flex flex-wrap items-start gap-8">
                    <StatTileSkeleton size="sm" />
                    <StatTileSkeleton size="md" />
                    <StatTileSkeleton size="lg" withTrend />
                </div>
            </Specimen>
        </div>
    );
}

const MONTHLY_SPENDING = [
    {
        month: 'Jun',
        groceries: 41000,
        rent: 95000,
        travel: 12000,
        other: 18000,
    },
    {
        month: 'Jul',
        groceries: 39000,
        rent: 95000,
        travel: 64000,
        other: 21000,
    },
    {
        month: 'Aug',
        groceries: 45000,
        rent: 95000,
        travel: 38000,
        other: 15000,
    },
    { month: 'Sep', groceries: 42000, rent: 98000, travel: 9000, other: 26000 },
    {
        month: 'Oct',
        groceries: 47000,
        rent: 98000,
        travel: 15000,
        other: 19000,
    },
    {
        month: 'Nov',
        groceries: 44000,
        rent: 98000,
        travel: 22000,
        other: 23000,
    },
];

const SPENDING_KEYS = ['groceries', 'rent', 'travel', 'other'];

const SPENDING_CONFIG: ChartConfig = {
    groceries: { label: 'Groceries' },
    rent: { label: 'Rent' },
    travel: { label: 'Travel' },
    other: { label: 'Other' },
};

function formatSampleAmount(value: number) {
    return <AmountDisplay amountInCents={value} currencyCode="EUR" />;
}

function Charts() {
    const chartProps = {
        data: MONTHLY_SPENDING,
        dataKeys: SPENDING_KEYS,
        config: SPENDING_CONFIG,
        xAxisKey: 'month',
        valueFormatter: formatSampleAmount,
        displayCurrency: 'EUR',
        className: 'h-64 w-full',
    };

    return (
        <div className="grid w-full gap-6 lg:grid-cols-2">
            <Specimen label="StackedBarChart">
                <StackedBarChart {...chartProps} />
            </Specimen>
            <Specimen label="StackedAreaChart">
                <StackedAreaChart {...chartProps} />
            </Specimen>
        </div>
    );
}

export const FINANCE_PREVIEWS: PreviewEntry[] = [
    { id: 'amounts', title: 'Amounts', Component: Amounts },
    {
        id: 'trend-indicators',
        title: 'Trend indicators',
        Component: TrendIndicators,
    },
    { id: 'stat-tiles', title: 'Stat tiles', Component: StatTiles },
    { id: 'charts', title: 'Charts', Component: Charts },
];

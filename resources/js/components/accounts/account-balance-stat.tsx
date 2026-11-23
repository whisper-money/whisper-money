import { AmountTrendIndicator } from '@/components/dashboard/amount-trend-indicator';
import { AmountDisplay } from '@/components/ui/amount-display';
import {
    StatTile,
    StatTileTrend,
    StatTileValue,
} from '@/components/ui/stat-tile';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

interface AccountBalanceStatProps {
    balance: number;
    previousBalance: number;
    currencyCode: string;
    /**
     * Opens the balance editor when the balance is typed in by hand. A
     * connected account's balance comes from the bank, so it is not a button.
     */
    onEditBalance?: () => void;
    /** Ends the figure on the right from `sm` up, as the accounts list does. */
    alignEnd?: boolean;
}

/** An account's balance with how it moved since last month. */
export function AccountBalanceStat({
    balance,
    previousBalance,
    currencyCode,
    onEditBalance,
    alignEnd = false,
}: AccountBalanceStatProps) {
    const diff = balance - previousBalance;
    const amount = (
        <AmountDisplay amountInCents={balance} currencyCode={currencyCode} />
    );
    const offset = cn('-ml-2 px-2 py-1', alignEnd && 'sm:-mr-2 sm:ml-0');

    return (
        <StatTile
            size="lg"
            className={cn(alignEnd && 'shrink-0 items-start sm:items-end')}
        >
            <StatTileValue>
                {onEditBalance ? (
                    <button
                        type="button"
                        onClick={onEditBalance}
                        className={cn(
                            offset,
                            'cursor-pointer rounded-md text-left transition-colors hover:bg-muted',
                        )}
                    >
                        {amount}
                    </button>
                ) : (
                    <div className={offset}>{amount}</div>
                )}
            </StatTileValue>
            <StatTileTrend>
                <AmountTrendIndicator
                    isPositive={diff >= 0}
                    trend={Math.abs(diff)}
                    label={__('vs last month')}
                    previousAmount={previousBalance}
                    currentAmount={balance}
                    tooltipSide="bottom"
                    currencyCode={currencyCode}
                />
            </StatTileTrend>
        </StatTile>
    );
}

import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { Button } from '@/components/ui/button';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { trendLabel } from '@/lib/forecast/format';
import { cn } from '@/lib/utils';
import {
    type ForecastAccountTab,
    type ForecastHorizon,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Settings2Icon } from 'lucide-react';

const PILL =
    'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] transition-colors';
const PILL_ON =
    'border-primary bg-primary font-semibold text-primary-foreground';
const PILL_OFF = 'border-border bg-background text-foreground hover:bg-muted';

interface ForecastAccountTabsProps {
    accounts: ForecastAccountTab[];
    /** The account shown, or null for "All accounts". */
    selectedId: string | null;
    horizon: ForecastHorizon;
}

/**
 * "All accounts", then the accounts the forecast covers, one pill each, the
 * one with a problem marked with a red dot and a projected-only one said
 * so, with the trend of one that only has a balance history, then the way
 * to choose the accounts.
 */
export function ForecastAccountTabs({
    accounts,
    selectedId,
    horizon,
}: ForecastAccountTabsProps) {
    const editor = useForecastEditor();
    const { formatAmount } = useFormatAmount();

    return (
        <nav aria-label={__('Account')} className="flex flex-wrap gap-2">
            <Link
                href={forecastIndex({ query: { account: 'all', horizon } })}
                preserveScroll
                aria-current={selectedId === null ? 'page' : undefined}
                className={cn(PILL, selectedId === null ? PILL_ON : PILL_OFF)}
                data-test="forecast-tab-all"
            >
                {__('All accounts')}
            </Link>
            {accounts.map((account) => {
                const selected = account.id === selectedId;
                const trend = trendLabel(
                    account.trend,
                    (amount) =>
                        formatAmount(amount, account.currencyCode, {
                            fractionDigits: 0,
                        }),
                    true,
                );

                return (
                    <Link
                        key={account.id}
                        href={forecastIndex({
                            query: { account: account.id, horizon },
                        })}
                        preserveScroll
                        aria-current={selected ? 'page' : undefined}
                        className={cn(PILL, selected ? PILL_ON : PILL_OFF)}
                        data-test={`forecast-tab-${account.id}`}
                    >
                        {account.name}
                        {account.projectionOnly && (
                            <span className="text-xs opacity-70">
                                {__('projection only')}
                            </span>
                        )}
                        {trend && (
                            <span className="text-xs opacity-70">{trend}</span>
                        )}
                        {account.hasAlert && (
                            <span
                                role="img"
                                aria-label={__('has a warning')}
                                className="size-2 rounded-full bg-destructive"
                            />
                        )}
                    </Link>
                );
            })}
            {editor && (
                <Button
                    variant="ghost"
                    size="sm"
                    className="min-h-9 rounded-full text-muted-foreground"
                    onClick={() => editor.open({ kind: 'accounts' })}
                    data-test="forecast-choose-accounts"
                >
                    <Settings2Icon />
                    {__('Choose accounts')}
                </Button>
            )}
        </nav>
    );
}

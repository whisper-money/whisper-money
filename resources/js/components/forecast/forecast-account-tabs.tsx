import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { cn } from '@/lib/utils';
import {
    type ForecastAccountTab,
    type ForecastHorizon,
} from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';

const PILL =
    'inline-flex min-h-9 items-center gap-1.5 rounded-full border px-3.5 text-[13px] transition-colors';
const PILL_ON =
    'border-primary bg-primary font-semibold text-primary-foreground';
const PILL_OFF = 'border-border bg-background text-foreground hover:bg-muted';

interface ForecastAccountTabsProps {
    accounts: ForecastAccountTab[];
    selectedId: string;
    horizon: ForecastHorizon;
}

/**
 * The accounts the forecast covers, one pill each, the one with a problem
 * marked with a red dot. "All accounts" holds the place of the combined view,
 * which comes later.
 */
export function ForecastAccountTabs({
    accounts,
    selectedId,
    horizon,
}: ForecastAccountTabsProps) {
    return (
        <nav aria-label={__('Account')} className="flex flex-wrap gap-2">
            <span
                aria-disabled="true"
                title={__('Coming soon')}
                className={cn(
                    PILL,
                    PILL_OFF,
                    'cursor-default opacity-60 hover:bg-background',
                )}
                data-test="forecast-tab-all"
            >
                {__('All accounts')}
            </span>
            {accounts.map((account) => {
                const selected = account.id === selectedId;

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
        </nav>
    );
}

import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { cn } from '@/lib/utils';
import { type ForecastHorizon } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';

const HORIZONS: ForecastHorizon[] = [3, 6, 12];

/** How many months ahead the forecast looks: 3, 6 or 12. */
export function ForecastHorizonPicker({
    horizon,
    accountId,
}: {
    horizon: ForecastHorizon;
    accountId: string | null;
}) {
    return (
        <div
            role="group"
            aria-label={__('Horizon')}
            className="flex gap-0.5 rounded-lg bg-muted p-[3px]"
        >
            {HORIZONS.map((option) => {
                const selected = option === horizon;

                return (
                    <Link
                        key={option}
                        href={forecastIndex({
                            query: {
                                ...(accountId ? { account: accountId } : {}),
                                horizon: option,
                            },
                        })}
                        preserveScroll
                        aria-pressed={selected}
                        className={cn(
                            'inline-flex min-h-8 items-center rounded-md px-3 text-[13px]',
                            selected
                                ? 'bg-background font-semibold text-foreground shadow-xs'
                                : 'text-muted-foreground hover:text-foreground',
                        )}
                        data-test={`forecast-horizon-${option}`}
                    >
                        {__(':count months', { count: option })}
                    </Link>
                );
            })}
        </div>
    );
}

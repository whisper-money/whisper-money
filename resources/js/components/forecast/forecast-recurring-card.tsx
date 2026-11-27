import {
    store as drop,
    destroy as undoDrop,
} from '@/actions/App/Http/Controllers/Forecast/ForecastRecurringDropController';
import {
    store as keep,
    destroy as undoKeep,
} from '@/actions/App/Http/Controllers/Forecast/ForecastRecurringKeepController';
import { ForecastSection } from '@/components/forecast/forecast-section';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
import { useForecastSave } from '@/hooks/use-forecast-save';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { monthWithYear } from '@/lib/forecast/format';
import {
    type ForecastRecurringCharge,
    type ForecastRecurringCharges,
} from '@/types/forecast';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { type ReactNode, useEffect, useState } from 'react';

/** How many charges show before "See all". */
export const COLLAPSED_COUNT = 5;

function frequencyLabel(
    frequency: ForecastRecurringCharge['frequency'],
): string {
    switch (frequency) {
        case 'weekly':
            return __('Weekly');
        case 'monthly':
            return __('Monthly');
        case 'bimonthly':
            return __('Bimonthly');
        case 'quarterly':
            return __('Quarterly');
        case 'yearly':
            return __('Yearly');
    }
}

/**
 * The card while its data is on its way, with the last data it had kept
 * through the reload that follows an action, so it does not blink.
 */
function useLastCharges(
    charges: ForecastRecurringCharges | undefined,
): ForecastRecurringCharges | undefined {
    const [last, setLast] = useState(charges);

    useEffect(() => {
        if (charges !== undefined) {
            setLast(charges);
        }
    }, [charges]);

    return charges ?? last;
}

type RouteDefinition = {
    url: string;
    method: 'get' | 'post' | 'put' | 'patch' | 'delete';
};

/** Sends one of the card's actions. */
type RunAction = (
    route: RouteDefinition,
    data?: Record<string, string>,
) => void;

/** A state badge and its Undo. */
function MarkedCharge({
    charge,
    label,
    test,
    busy,
    onUndo,
}: {
    charge: ForecastRecurringCharge;
    label: string;
    test: string;
    busy: boolean;
    onUndo: () => void;
}) {
    return (
        <div className="flex flex-wrap items-center gap-2">
            <Badge variant="secondary" data-test={test}>
                {label}
            </Badge>
            <Button
                variant="link"
                size="sm"
                className="h-auto px-0"
                disabled={busy}
                onClick={onUndo}
                aria-label={__('Undo for :name', { name: charge.name })}
                data-test={`${test}-undo`}
            >
                {__('Undo')}
            </Button>
        </div>
    );
}

/** What the user can do about a charge, or what they already did. */
function ChargeActions({
    charge,
    busy,
    run,
}: {
    charge: ForecastRecurringCharge;
    busy: boolean;
    run: RunAction;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();
    const key = { key: charge.key };

    if (charge.drop !== null) {
        const { id, month, reduction } = charge.drop;
        const dropped = __('Dropped from :month', {
            month: monthWithYear(month, locale),
        });

        return (
            <MarkedCharge
                charge={charge}
                label={
                    reduction < charge.monthly
                        ? __(':dropped · the forecast only had :amount left', {
                              dropped,
                              amount: formatAmount(
                                  reduction,
                                  charge.currencyCode,
                              ),
                          })
                        : dropped
                }
                test="recurring-dropped"
                busy={busy}
                onUndo={() => run(undoDrop(id))}
            />
        );
    }

    if (charge.kept) {
        return (
            <MarkedCharge
                charge={charge}
                label={__('Kept')}
                test="recurring-kept"
                busy={busy}
                onUndo={() => run(undoKeep(), key)}
            />
        );
    }

    return (
        <div className="flex items-center gap-2">
            {charge.canDrop && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() => run(drop(), key)}
                    aria-label={__('I’m dropping :name', {
                        name: charge.name,
                    })}
                    data-test="recurring-drop"
                >
                    {__('I’m dropping it')}
                </Button>
            )}
            <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() => run(keep(), key)}
                aria-label={__('Keep :name', { name: charge.name })}
                data-test="recurring-keep"
            >
                {__('Keep')}
            </Button>
        </div>
    );
}

/** "Monthly · Checking · last charged on … · ≈ €192 a year". */
function chargeDetails(
    charge: ForecastRecurringCharge,
    locale: string,
    formatAmount: (amount: number, currencyCode: string) => string,
): string {
    return [
        frequencyLabel(charge.frequency),
        charge.frequency === 'monthly'
            ? null
            : __('≈ :amount a month', {
                  amount: formatAmount(charge.monthly, charge.currencyCode),
              }),
        charge.accountName,
        __('last charged on :date', {
            date: formatDate(charge.lastChargedOn, 'd MMM yyyy', locale),
        }),
        __('≈ :amount a year', {
            amount: formatAmount(charge.yearly, charge.currencyCode),
        }),
    ]
        .filter(Boolean)
        .join(' · ');
}

function ChargeRow({
    charge,
    busy,
    run,
}: {
    charge: ForecastRecurringCharge;
    busy: boolean;
    run: RunAction;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();

    return (
        <li
            className="flex flex-col gap-2 py-3 sm:flex-row sm:items-center sm:justify-between sm:gap-4"
            data-test="recurring-charge"
        >
            <div className="flex min-w-0 flex-col gap-0.5">
                <div className="flex items-baseline gap-2">
                    <span className="truncate font-medium">{charge.name}</span>
                    <AmountDisplay
                        amountInCents={-charge.amount}
                        currencyCode={charge.currencyCode}
                        className="shrink-0 tabular-nums"
                    />
                </div>
                <p className="text-xs text-muted-foreground">
                    {chargeDetails(charge, locale, formatAmount)}
                </p>
            </div>
            <ChargeActions charge={charge} busy={busy} run={run} />
        </li>
    );
}

function CardShell({
    total,
    children,
}: {
    /** What the charges add up to a month, once they are loaded. */
    total?: string;
    children: ReactNode;
}) {
    return (
        <ForecastSection
            title={__('Recurring transactions')}
            description={__(
                'The charges that repeat in your accounts. Mark the ones you are dropping and the forecast stops counting them from next month.',
            )}
            aside={
                total && (
                    <p
                        className="text-sm font-medium tabular-nums"
                        data-test="recurring-total"
                    >
                        {__(':amount/month', { amount: total })}
                    </p>
                )
            }
        >
            <Card className="gap-0 py-0" data-test="forecast-recurring-charges">
                <CardContent className="px-4">{children}</CardContent>
            </Card>
        </ForecastSection>
    );
}

/**
 * "Recurring transactions": the charges that repeat, the most expensive a
 * month first, collapsed to the top five. Each can be dropped (its category's
 * estimate goes down from next month) or kept; both stay listed, marked, with
 * an Undo. The data is deferred: a skeleton stands in until it comes.
 */
export function ForecastRecurringCard({
    recurringCharges,
}: {
    recurringCharges: ForecastRecurringCharges | undefined;
}) {
    const data = useLastCharges(recurringCharges);
    const { formatAmount } = useFormatAmount();
    const [expanded, setExpanded] = useState(false);
    const { save, processing } = useForecastSave();
    const [reloading, setReloading] = useState(false);
    const busy = processing || reloading;

    if (data === undefined) {
        return (
            <CardShell>
                <div
                    className="flex flex-col gap-3 py-4"
                    data-test="recurring-loading"
                >
                    <Skeleton className="h-5 w-2/3" />
                    <Skeleton className="h-5 w-1/2" />
                    <Skeleton className="h-5 w-3/5" />
                </div>
            </CardShell>
        );
    }

    const total =
        data.totals.length === 0
            ? undefined
            : data.totals
                  .map((total) =>
                      formatAmount(total.monthly, total.currencyCode),
                  )
                  .join(' + ');
    const shown = expanded
        ? data.charges
        : data.charges.slice(0, COLLAPSED_COUNT);
    // The plan reloads with the save; the card reloads right after it.
    const run: RunAction = (route, payload = {}) =>
        save(
            route,
            payload,
            null,
            () =>
                router.reload({
                    only: ['recurringCharges'],
                    onStart: () => setReloading(true),
                    onFinish: () => setReloading(false),
                }),
            { toastErrors: true },
        );

    return (
        <CardShell total={total}>
            {data.charges.length === 0 ? (
                <EmptyState size="inline">
                    <EmptyStateTitle>
                        {__('Nothing repeats yet')}
                    </EmptyStateTitle>
                    <EmptyStateDescription>
                        {__(
                            'When a charge comes back every month, quarter or year, it shows up here.',
                        )}
                    </EmptyStateDescription>
                </EmptyState>
            ) : (
                <>
                    <ul className="divide-y">
                        {shown.map((charge) => (
                            <ChargeRow
                                key={charge.key}
                                charge={charge}
                                busy={busy}
                                run={run}
                            />
                        ))}
                    </ul>
                    {data.charges.length > COLLAPSED_COUNT && (
                        <div className="border-t py-2">
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => setExpanded(!expanded)}
                                data-test="recurring-see-all"
                            >
                                {expanded
                                    ? __('Show less')
                                    : __('See all (:count)', {
                                          count: data.charges.length,
                                      })}
                            </Button>
                        </div>
                    )}
                </>
            )}
        </CardShell>
    );
}

import {
    store as drop,
    destroy as undoDrop,
} from '@/actions/App/Http/Controllers/Forecast/ForecastRecurringDropController';
import {
    store as keep,
    destroy as undoKeep,
} from '@/actions/App/Http/Controllers/Forecast/ForecastRecurringKeepController';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
import { Skeleton } from '@/components/ui/skeleton';
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

/** What the user can do about a charge, or what they already did. */
function ChargeActions({
    charge,
    busy,
    onAction,
}: {
    charge: ForecastRecurringCharge;
    busy: boolean;
    onAction: (run: (onFinish: () => void) => void) => void;
}) {
    const locale = useLocale();
    const options = (onFinish: () => void) => ({
        preserveScroll: true,
        onFinish,
    });

    if (charge.drop !== null) {
        const dropId = charge.drop.id;

        return (
            <div className="flex items-center gap-2">
                <Badge variant="secondary" data-test="recurring-dropped">
                    {__('Dropped from :month', {
                        month: monthWithYear(charge.drop.month, locale),
                    })}
                </Badge>
                <Button
                    variant="link"
                    size="sm"
                    className="h-auto px-0"
                    disabled={busy}
                    onClick={() =>
                        onAction((onFinish) =>
                            router.delete(
                                undoDrop(dropId).url,
                                options(onFinish),
                            ),
                        )
                    }
                    data-test="recurring-undo-drop"
                >
                    {__('Undo')}
                </Button>
            </div>
        );
    }

    if (charge.kept) {
        return (
            <div className="flex items-center gap-2">
                <Badge variant="outline" data-test="recurring-kept">
                    {__('Kept')}
                </Badge>
                <Button
                    variant="link"
                    size="sm"
                    className="h-auto px-0"
                    disabled={busy}
                    onClick={() =>
                        onAction((onFinish) =>
                            router.delete(undoKeep().url, {
                                ...options(onFinish),
                                data: { key: charge.key },
                            }),
                        )
                    }
                    data-test="recurring-undo-keep"
                >
                    {__('Undo')}
                </Button>
            </div>
        );
    }

    return (
        <div className="flex items-center gap-2">
            {charge.canDrop && (
                <Button
                    variant="outline"
                    size="sm"
                    disabled={busy}
                    onClick={() =>
                        onAction((onFinish) =>
                            router.post(
                                drop().url,
                                { key: charge.key },
                                options(onFinish),
                            ),
                        )
                    }
                    data-test="recurring-drop"
                >
                    {__('I’m dropping it')}
                </Button>
            )}
            <Button
                variant="ghost"
                size="sm"
                disabled={busy}
                onClick={() =>
                    onAction((onFinish) =>
                        router.post(
                            keep().url,
                            { key: charge.key },
                            options(onFinish),
                        ),
                    )
                }
                data-test="recurring-keep"
            >
                {__('Keep')}
            </Button>
        </div>
    );
}

function ChargeRow({
    charge,
    busy,
    onAction,
}: {
    charge: ForecastRecurringCharge;
    busy: boolean;
    onAction: (run: (onFinish: () => void) => void) => void;
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
                    {[
                        frequencyLabel(charge.frequency),
                        charge.accountName,
                        __('last charged on :date', {
                            date: formatDate(
                                charge.lastChargedOn,
                                'd MMM yyyy',
                                locale,
                            ),
                        }),
                        __('≈ :amount a year', {
                            amount: formatAmount(
                                charge.yearly,
                                charge.currencyCode,
                            ),
                        }),
                    ]
                        .filter(Boolean)
                        .join(' · ')}
                </p>
            </div>
            <ChargeActions charge={charge} busy={busy} onAction={onAction} />
        </li>
    );
}

function CardShell({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <Card className="gap-0 py-0" data-test="forecast-recurring-charges">
            <CardHeader className="flex flex-col gap-0.5 border-b px-4 py-3.5">
                <CardTitle>{title}</CardTitle>
                <CardDescription>
                    {__(
                        'The charges that repeat in your accounts. Drop the ones you are cancelling and the forecast stops counting them from next month.',
                    )}
                </CardDescription>
            </CardHeader>
            <CardContent className="px-4">{children}</CardContent>
        </Card>
    );
}

/**
 * "What you pay every month": the charges that repeat, the most expensive a
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
    const [busy, setBusy] = useState(false);

    if (data === undefined) {
        return (
            <CardShell title={__('What you pay every month')}>
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

    const title =
        data.totals.length === 0
            ? __('What you pay every month')
            : __('What you pay every month · :amount/month', {
                  amount: data.totals
                      .map((total) =>
                          formatAmount(total.monthly, total.currencyCode),
                      )
                      .join(' + '),
              });
    const shown = expanded
        ? data.charges
        : data.charges.slice(0, COLLAPSED_COUNT);
    const onAction = (run: (onFinish: () => void) => void) => {
        setBusy(true);
        run(() => setBusy(false));
    };

    return (
        <CardShell title={title}>
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
                                onAction={onAction}
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

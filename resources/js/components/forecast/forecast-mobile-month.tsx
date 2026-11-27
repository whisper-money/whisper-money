import { useForecastEditor } from '@/components/forecast/forecast-editor';
import { KindBadge } from '@/components/forecast/forecast-plan-cells';
import { AddLineButton } from '@/components/forecast/forecast-plan-table';
import { UpgradeDialog } from '@/components/subscription/upgrade-dialog';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    EmptyState,
    EmptyStateDescription,
    EmptyStateHeader,
    EmptyStateMedia,
    EmptyStateTitle,
} from '@/components/ui/empty-state';
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
    monthShortLabel,
    monthWithYear,
} from '@/lib/forecast/format';
import {
    type MobileMonthEntry,
    type MobileMonthGroup,
} from '@/lib/forecast/mobile-month';
import { type ProjectedMonth } from '@/lib/forecast/types';
import { cn } from '@/lib/utils';
import { type ForecastMonthColumn } from '@/types/forecast';
import { __ } from '@/utils/i18n';
import { ChevronRightIcon, LockIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

const CHIP =
    'inline-flex min-h-10 flex-none items-center gap-1 rounded-full border px-3 text-[13px] transition-colors';

/**
 * The months as a row of chips that scrolls sideways: the one picked filled,
 * those that run short marked ⚠, those of the free plan locked.
 */
export function ForecastMonthChips({
    months,
    selectedMonth,
    isShortMonth,
    onSelectMonth,
}: {
    months: ForecastMonthColumn[];
    selectedMonth: string | null;
    isShortMonth: (month: string) => boolean;
    onSelectMonth: (month: string) => void;
}) {
    const locale = useLocale();
    const selectedRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        selectedRef.current?.scrollIntoView?.({
            block: 'nearest',
            inline: 'center',
        });
    }, [selectedMonth]);

    return (
        <div
            role="group"
            aria-label={__('Month')}
            className="-mx-4 flex gap-1.5 overflow-x-auto px-4 pb-0.5 [scrollbar-width:none]"
            data-test="forecast-month-chips"
        >
            {months.map((column) => {
                const selected = column.month === selectedMonth;
                const locked = column.status === 'locked';
                const short = !locked && isShortMonth(column.month);

                return (
                    <button
                        key={column.month}
                        ref={selected ? selectedRef : undefined}
                        type="button"
                        aria-pressed={selected}
                        onClick={() => onSelectMonth(column.month)}
                        className={cn(
                            CHIP,
                            selected
                                ? 'border-primary bg-primary font-semibold text-primary-foreground'
                                : 'border-border bg-background text-foreground',
                            !selected &&
                                short &&
                                'border-destructive/30 text-destructive',
                            !selected && locked && 'text-muted-foreground',
                        )}
                        data-test={`forecast-month-chip-${column.month}`}
                        data-locked={locked || undefined}
                    >
                        {locked && (
                            <LockIcon
                                aria-label={__('Available with Pro')}
                                className="size-3"
                            />
                        )}
                        {monthShortLabel(column.month, locale)}
                        {short && (
                            <span role="img" aria-label={__('has a warning')}>
                                ⚠
                            </span>
                        )}
                    </button>
                );
            })}
        </div>
    );
}

function useNumber(): (amount: number, currencyCode: string) => string {
    const { formatAmountNumber } = useFormatAmount();

    return (amount, currencyCode) =>
        formatAmountNumber(amount, currencyCode, { fractionDigits: 0 });
}

function EntryRow({
    entry,
    groupCurrency,
}: {
    entry: MobileMonthEntry;
    /** The row's own currency, so an entry in another one says which. */
    groupCurrency: string;
}) {
    const editor = useForecastEditor();
    const number = useNumber();
    const { formatAmount } = useFormatAmount();
    const target = editor === null ? null : entry.target;
    const income = entry.amount > 0 && entry.kind !== 'transfer';
    const figure =
        entry.currencyCode === groupCurrency
            ? number(entry.amount, entry.currencyCode)
            : formatAmount(entry.amount, entry.currencyCode, {
                  fractionDigits: 0,
              });
    const content = (
        <>
            <span className="flex min-w-0 flex-1 flex-col gap-0.5 text-left">
                <span className="truncate text-[13px]">{entry.name}</span>
                <span className="flex flex-wrap items-center gap-1.5">
                    <KindBadge kind={entry.kind} />
                    {entry.accountName && (
                        <span className="text-[11px] text-muted-foreground">
                            {entry.accountName}
                        </span>
                    )}
                </span>
            </span>
            <span className="flex flex-col items-end gap-px">
                <span
                    className={cn(
                        'text-[13px] tabular-nums',
                        entry.approximate && 'text-muted-foreground',
                        income && 'text-success',
                    )}
                >
                    {entry.approximate && '≈ '}
                    {income && '+'}
                    {figure}
                </span>
                {entry.status && (
                    <span className="text-[10px] text-muted-foreground">
                        {entry.status === 'paid' ? __('paid') : __('pending')}
                    </span>
                )}
            </span>
        </>
    );
    const className = 'flex min-h-11 w-full items-center gap-2';

    if (target === null) {
        return (
            <div className={className} data-test={`forecast-entry-${entry.id}`}>
                {content}
            </div>
        );
    }

    return (
        <button
            type="button"
            className={cn(
                className,
                'rounded-md focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none active:bg-muted',
            )}
            onClick={() => editor?.open(target)}
            data-test={`forecast-entry-${entry.id}`}
        >
            {content}
        </button>
    );
}

function GroupRow({
    group,
    open,
    onToggle,
}: {
    group: MobileMonthGroup;
    open: boolean;
    onToggle: () => void;
}) {
    const number = useNumber();
    const income = group.kind === 'income';

    return (
        <div
            className="border-t"
            data-test={`forecast-month-group-${group.id}`}
        >
            <button
                type="button"
                onClick={onToggle}
                aria-expanded={open}
                className="flex min-h-13 w-full items-center gap-2 px-3.5 text-left"
            >
                <ChevronRightIcon
                    aria-hidden="true"
                    className={cn(
                        'size-3.5 shrink-0 text-muted-foreground transition-transform',
                        open && 'rotate-90',
                    )}
                />
                <span className="flex min-w-0 flex-1 flex-col gap-px py-2">
                    <span className="text-sm font-medium">{group.name}</span>
                    <span className="truncate text-[11px] text-muted-foreground">
                        {group.hint}
                    </span>
                </span>
                <span
                    className={cn(
                        'text-sm font-semibold tabular-nums',
                        income && group.total > 0 && 'text-success',
                    )}
                >
                    {group.approximate && '≈ '}
                    {income && group.total > 0 && '+'}
                    {number(group.total, group.currencyCode)}
                </span>
            </button>
            {open && (
                <div className="flex flex-col gap-0.5 pr-3.5 pb-2.5 pl-9">
                    {group.entries.map((entry) => (
                        <EntryRow
                            key={entry.id}
                            entry={entry}
                            groupCurrency={group.currencyCode}
                        />
                    ))}
                    {group.add && (
                        <span className="flex min-h-8 items-center">
                            <AddLineButton
                                section={group.add.section}
                                group={group.add.group}
                            />
                        </span>
                    )}
                </div>
            )}
        </div>
    );
}

/** A month the free plan does not show: what Pro would show instead of its figures. */
export function LockedMonthCard({ month }: { month: string }) {
    const locale = useLocale();
    const [open, setOpen] = useState(false);

    return (
        <Card size="sm" data-test="forecast-month-locked">
            <h2 className="px-(--card-px) text-base font-semibold first-letter:uppercase">
                {monthWithYear(month, locale)}
            </h2>
            <EmptyState size="inline">
                <EmptyStateHeader>
                    <EmptyStateMedia variant="icon">
                        <LockIcon />
                    </EmptyStateMedia>
                    <EmptyStateTitle>
                        {__('Available with Pro')}
                    </EmptyStateTitle>
                    <EmptyStateDescription>
                        {__(
                            'On the free plan you see next month. With Pro you see 12 months, we warn you before an account runs short and you can edit your plan.',
                        )}
                    </EmptyStateDescription>
                </EmptyStateHeader>
                <Button onClick={() => setOpen(true)}>
                    {__('Unlock 12 months with Pro')}
                </Button>
            </EmptyState>
            <UpgradeDialog
                open={open}
                onOpenChange={setOpen}
                source="forecast"
            />
        </Card>
    );
}

/**
 * One month of the plan on the phone: its lowest balance and worst case,
 * how it closes, and the income, each parent category and the transfers of
 * that month, each opening into what makes it up.
 */
export function ForecastMonthCard({
    month,
    projected,
    threshold,
    currencyCode,
    groups,
    initiallyOpen,
}: {
    month: string;
    projected: ProjectedMonth | undefined;
    threshold: number | null;
    currencyCode: string;
    groups: MobileMonthGroup[];
    /** The rows open on the first render, by id. */
    initiallyOpen: string[];
}) {
    const locale = useLocale();
    const [open, setOpen] = useState<Set<string>>(() => new Set(initiallyOpen));
    const toggle = (id: string) =>
        setOpen((current) => {
            const next = new Set(current);

            if (!next.delete(id)) {
                next.add(id);
            }

            return next;
        });

    return (
        <Card size="flush" data-test="forecast-month-card">
            <div className="flex flex-col gap-2 px-3.5 pt-3.5 pb-2.5">
                <h2 className="text-base font-semibold first-letter:uppercase">
                    {monthWithYear(month, locale)}
                </h2>
                {projected && (
                    <div className="grid grid-cols-2 gap-2">
                        <StatTile size="sm">
                            <StatTileLabel>
                                {__('Minimum of the month')}
                            </StatTileLabel>
                            <StatTileValue
                                className={balanceTone(
                                    projected.min,
                                    threshold,
                                )}
                            >
                                <AmountDisplay
                                    amountInCents={projected.min}
                                    currencyCode={currencyCode}
                                    maximumFractionDigits={0}
                                    minimumFractionDigits={0}
                                />
                            </StatTileValue>
                            <StatTileDescription>
                                <WorstCase
                                    amount={projected.pessimisticMin}
                                    currencyCode={currencyCode}
                                />
                            </StatTileDescription>
                        </StatTile>
                        <StatTile size="sm">
                            <StatTileLabel>
                                {__('Closing balance')}
                            </StatTileLabel>
                            <StatTileValue>
                                <AmountDisplay
                                    amountInCents={projected.close}
                                    currencyCode={currencyCode}
                                    maximumFractionDigits={0}
                                    minimumFractionDigits={0}
                                />
                            </StatTileValue>
                        </StatTile>
                    </div>
                )}
            </div>
            {groups.length === 0 ? (
                <EmptyState size="inline" className="border-t">
                    <EmptyStateDescription>
                        {__('Nothing planned this month.')}
                    </EmptyStateDescription>
                </EmptyState>
            ) : (
                groups.map((group) => (
                    <GroupRow
                        key={group.id}
                        group={group}
                        open={open.has(group.id)}
                        onToggle={() => toggle(group.id)}
                    />
                ))
            )}
        </Card>
    );
}

function WorstCase({
    amount,
    currencyCode,
}: {
    amount: number;
    currencyCode: string;
}) {
    const { formatAmount } = useFormatAmount();

    return __('worst case :amount', {
        amount: formatAmount(amount, currencyCode, { fractionDigits: 0 }),
    });
}

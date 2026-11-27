import { Alert, AlertDescription } from '@/components/ui/alert';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { useFormatAmount } from '@/hooks/use-format-amount';
import { useLocale } from '@/hooks/use-locale';
import { useIsMobile } from '@/hooks/use-mobile';
import { monthLongName, monthWithYear } from '@/lib/forecast/format';
import { type ForecastFrequency } from '@/lib/forecast/types';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { CircleAlertIcon, InfoIcon } from 'lucide-react';
import { type ReactNode, useId } from 'react';

/**
 * The pieces every edit sheet of the forecast is built from: the side
 * panel itself, the "which months" choice, the repetition picker, the
 * impact callout and the before → after table.
 */

export function ForecastSheet({
    open,
    onOpenChange,
    eyebrow,
    title,
    children,
    footer,
    testId,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    eyebrow?: string;
    title: string;
    children: ReactNode;
    footer: ReactNode;
    testId: string;
}) {
    const isMobile = useIsMobile();

    return (
        <Sheet open={open} onOpenChange={onOpenChange}>
            <SheetContent
                side={isMobile ? 'bottom' : 'right'}
                className={cn(
                    'flex w-full flex-col gap-0',
                    isMobile
                        ? 'h-[calc(100dvh-env(safe-area-inset-top)-1rem)] rounded-t-xl pb-[env(safe-area-inset-bottom)]'
                        : 'sm:max-w-[480px]',
                )}
                data-test={testId}
            >
                <SheetHeader className="gap-1 border-b px-6 pt-6 pb-4">
                    {eyebrow ? (
                        <SheetDescription className="text-xs">
                            {eyebrow}
                        </SheetDescription>
                    ) : (
                        <SheetDescription className="sr-only">
                            {title}
                        </SheetDescription>
                    )}
                    <SheetTitle className="text-xl">{title}</SheetTitle>
                </SheetHeader>
                <div className="flex flex-1 flex-col gap-5 overflow-y-auto px-6 py-5">
                    {children}
                </div>
                <SheetFooter className="flex-row flex-wrap justify-between gap-2 border-t px-6 py-4">
                    {footer}
                </SheetFooter>
            </SheetContent>
        </Sheet>
    );
}

export function Field({
    label,
    htmlFor,
    hint,
    error,
    children,
}: {
    label: string;
    htmlFor?: string;
    hint?: ReactNode;
    error?: string;
    children: ReactNode;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={htmlFor}>{label}</Label>
            {children}
            {hint && (
                <span className="text-xs text-muted-foreground">{hint}</span>
            )}
            {error && <p className="text-sm text-destructive">{error}</p>}
        </div>
    );
}

export interface ScopeOption<T extends string> {
    value: T;
    label: string;
    description: string;
}

/** "¿A qué meses afecta?": only this month, or from it on. */
export function ScopeChoice<T extends string>({
    value,
    onChange,
    options,
}: {
    value: T;
    onChange: (value: T) => void;
    options: ScopeOption<T>[];
}) {
    const id = useId();

    return (
        <fieldset className="flex flex-col gap-2">
            <legend className="mb-2 text-sm font-medium">
                {__('Which months does it affect?')}
            </legend>
            <RadioGroup
                value={value}
                onValueChange={(next) => onChange(next as T)}
                className="gap-2"
            >
                {options.map((option) => (
                    <label
                        key={option.value}
                        htmlFor={`${id}-${option.value}`}
                        className={cn(
                            'flex cursor-pointer items-start gap-3 rounded-lg border p-3',
                            option.value === value &&
                                'border-foreground ring-1 ring-foreground',
                        )}
                    >
                        <RadioGroupItem
                            id={`${id}-${option.value}`}
                            value={option.value}
                            className="mt-0.5"
                        />
                        <span className="flex flex-col gap-0.5">
                            <span className="text-sm font-medium">
                                {option.label}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {option.description}
                            </span>
                        </span>
                    </label>
                ))}
            </RadioGroup>
        </fieldset>
    );
}

export function frequencyLabel(frequency: ForecastFrequency): string {
    switch (frequency) {
        case 'once':
            return __('Once');
        case 'monthly':
            return __('Monthly');
        case 'bimonthly':
            return __('Every two months');
        case 'quarterly':
            return __('Quarterly');
        case 'yearly':
            return __('Yearly');
    }
}

const FREQUENCIES: ForecastFrequency[] = [
    'once',
    'monthly',
    'bimonthly',
    'quarterly',
    'yearly',
];

export function FrequencySelect({
    id,
    value,
    onChange,
    disabled,
    withOnce = true,
    only,
}: {
    id: string;
    value: ForecastFrequency;
    onChange: (value: ForecastFrequency) => void;
    disabled?: boolean;
    withOnce?: boolean;
    /** The repetitions offered, when not all of them. */
    only?: ForecastFrequency[];
}) {
    return (
        <Select
            value={value}
            onValueChange={(next) => onChange(next as ForecastFrequency)}
            disabled={disabled}
        >
            <SelectTrigger id={id} className="w-full">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {FREQUENCIES.filter(
                    (frequency) =>
                        (withOnce || frequency !== 'once') &&
                        (only === undefined || only.includes(frequency)),
                ).map((frequency) => (
                    <SelectItem key={frequency} value={frequency}>
                        {frequencyLabel(frequency)}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/** "Con este cambio, el mínimo de abril baja de −155 € a −255 €." */
export function ImpactCallout({
    impact,
    currencyCode,
    threshold,
    today,
}: {
    impact: { month: string; before: number; after: number } | null;
    currencyCode: string;
    threshold: number | null;
    /** Months of another year than today's are named with it. */
    today: string;
}) {
    const locale = useLocale();
    const { formatAmount } = useFormatAmount();

    if (impact === null) {
        return null;
    }

    const format = (amount: number) =>
        formatAmount(amount, currencyCode, { fractionDigits: 0 });
    const lowers = impact.after < impact.before;
    const short = threshold !== null && impact.after < threshold;
    const values = {
        month:
            impact.month.slice(0, 4) === today.slice(0, 4)
                ? monthLongName(impact.month, locale)
                : monthWithYear(impact.month, locale),
        before: format(impact.before),
        after: format(impact.after),
    };

    return (
        <Alert
            role="status"
            variant={short ? 'destructive' : 'default'}
            className={cn(short && 'border-destructive/30 bg-destructive/5')}
            data-test="forecast-impact"
        >
            <CircleAlertIcon />
            <AlertDescription
                className={cn('text-sm', short && 'text-destructive')}
            >
                {lowers
                    ? __(
                          'With this change, the minimum of :month drops from :before to :after.',
                          values,
                      )
                    : __(
                          'With this change, the minimum of :month rises from :before to :after.',
                          values,
                      )}
            </AlertDescription>
        </Alert>
    );
}

export function Note({ children }: { children: ReactNode }) {
    return (
        <div className="flex items-start gap-2.5 rounded-lg bg-muted p-3 text-sm text-muted-foreground">
            <InfoIcon className="mt-0.5 size-4 shrink-0" />
            <span>{children}</span>
        </div>
    );
}

export interface BeforeAfterRow {
    label: string;
    before: number;
    after: number;
    currencyCode: string;
    /** The balance below which the row reads as short; null never does. */
    threshold?: number | null;
}

/** "Así queda abril": each balance before and after the change. */
export function BeforeAfter({
    title,
    rows,
    note,
}: {
    title: string;
    rows: BeforeAfterRow[];
    note?: string;
}) {
    const { formatAmount } = useFormatAmount();

    return (
        <div
            className="flex flex-col gap-2.5 rounded-lg bg-muted/60 p-3.5"
            data-test="forecast-before-after"
        >
            <span className="text-sm font-medium">{title}</span>
            <div className="grid grid-cols-[minmax(0,1fr)_auto_auto_auto] items-center gap-x-3 gap-y-2 text-sm tabular-nums">
                {rows.map((row) => {
                    const format = (amount: number) =>
                        formatAmount(amount, row.currencyCode, {
                            fractionDigits: 0,
                        });
                    const shortBefore =
                        row.threshold != null && row.before < row.threshold;
                    const shortAfter =
                        row.threshold != null && row.after < row.threshold;
                    const changed = row.before !== row.after;

                    return (
                        <div key={row.label} className="contents">
                            <span className="text-muted-foreground">
                                {row.label}
                            </span>
                            <span
                                className={cn(
                                    'text-right text-muted-foreground',
                                    shortBefore && 'text-destructive',
                                    changed && 'line-through',
                                )}
                            >
                                {format(row.before)}
                            </span>
                            <span
                                aria-hidden="true"
                                className="text-muted-foreground"
                            >
                                →
                            </span>
                            <span
                                className={cn(
                                    'text-right font-semibold',
                                    shortAfter && 'text-destructive',
                                    changed &&
                                        shortBefore &&
                                        !shortAfter &&
                                        'text-success',
                                )}
                            >
                                {format(row.after)}
                            </span>
                        </div>
                    );
                })}
            </div>
            {note && (
                <span className="text-xs text-muted-foreground">{note}</span>
            )}
        </div>
    );
}

import { QuickPeriodButtons } from '@/components/transactions/transaction-filters-quick-periods';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label as FormLabel } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useLocale } from '@/hooks/use-locale';
import { periodLabel, stepPeriod } from '@/lib/transaction-period';
import { formatLocalDate, toLocalDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import {
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
} from 'lucide-react';

interface DateRangeChange {
    dateFrom: Date | null;
    dateTo: Date | null;
}

interface PeriodStepperProps extends DateRangeChange {
    onChange: (range: DateRangeChange) => void;
}

/**
 * ‹ [calendar] October 2026 › : the date filter as a period the arrows step
 * through, month by month (or by quarter, year, or the range's own length).
 * The label opens the quick periods and the custom from/to dates.
 */
export function PeriodStepper({
    dateFrom,
    dateTo,
    onChange,
}: PeriodStepperProps) {
    const locale = useLocale();

    function step(direction: -1 | 1) {
        const range = stepPeriod(dateFrom, dateTo, direction);
        if (range) {
            onChange({ dateFrom: range.from, dateTo: range.to });
        }
    }

    const canStep = stepPeriod(dateFrom, dateTo, 1) !== null;

    return (
        <div
            className="flex h-9 items-center rounded-md border border-input bg-background shadow-xs dark:bg-input/30"
            data-testid="period-stepper"
        >
            <Button
                variant="ghost"
                size="icon"
                className="h-[34px] rounded-r-none"
                aria-label={__('Previous period')}
                disabled={!canStep}
                onClick={() => step(-1)}
            >
                <ChevronLeft className="h-4 w-4" />
            </Button>
            <Popover>
                <PopoverTrigger asChild>
                    <Button
                        variant="ghost"
                        className="h-[34px] rounded-none px-2"
                        data-testid="period-stepper-label"
                    >
                        <CalendarDays className="h-4 w-4" />
                        {periodLabel(dateFrom, dateTo, locale)}
                        <ChevronDown className="h-4 w-4 opacity-50" />
                    </Button>
                </PopoverTrigger>
                <PopoverContent
                    className="w-80"
                    align="start"
                    data-testid="period-popover"
                >
                    <PeriodFields
                        dateFrom={dateFrom}
                        dateTo={dateTo}
                        onChange={onChange}
                        onClear={() =>
                            onChange({ dateFrom: null, dateTo: null })
                        }
                    />
                </PopoverContent>
            </Popover>
            <Button
                variant="ghost"
                size="icon"
                className="h-[34px] rounded-l-none"
                aria-label={__('Next period')}
                disabled={!canStep}
                onClick={() => step(1)}
            >
                <ChevronRight className="h-4 w-4" />
            </Button>
        </div>
    );
}

/**
 * Month / Quarter / Year and the custom from/to dates, the date filter's
 * fields wherever it is edited.
 */
export function PeriodFields({
    dateFrom,
    dateTo,
    onChange,
    onClear,
}: PeriodStepperProps & { onClear?: () => void }) {
    const dateInput = (bound: 'from' | 'to') => {
        const value = bound === 'from' ? dateFrom : dateTo;

        return (
            <Input
                type="date"
                data-testid={`filter-date-${bound}`}
                aria-label={bound === 'from' ? __('From') : __('To')}
                value={value ? formatLocalDate(value) : ''}
                onChange={(e) => {
                    const date = e.target.value
                        ? toLocalDate(e.target.value)
                        : null;
                    onChange(
                        bound === 'from'
                            ? { dateFrom: date, dateTo }
                            : { dateFrom, dateTo: date },
                    );
                }}
            />
        );
    };

    return (
        <div className="flex flex-col gap-2">
            <div className="flex items-center justify-between gap-2">
                <FormLabel>{__('Date')}</FormLabel>
                <QuickPeriodButtons
                    dateFrom={dateFrom}
                    dateTo={dateTo}
                    onChange={({ from, to }) =>
                        onChange({ dateFrom: from, dateTo: to })
                    }
                />
            </div>
            <div className="grid grid-cols-2 gap-2 pt-2">
                {dateInput('from')}
                {dateInput('to')}
            </div>
            {onClear && (dateFrom || dateTo) ? (
                <Button
                    variant="ghost"
                    size="sm"
                    className="self-start"
                    onClick={onClear}
                >
                    {__('All dates')}
                </Button>
            ) : null}
        </div>
    );
}

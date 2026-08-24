import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { CashflowPeriodType } from '@/hooks/use-cashflow-data';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { formatDate, formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import {
    addMonths,
    addQuarters,
    addYears,
    getQuarter,
    isSameMonth,
    isSameQuarter,
    isSameYear,
    startOfMonth,
    startOfQuarter,
    startOfYear,
    subMonths,
    subQuarters,
    subYears,
} from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PeriodNavigationProps {
    currentDate: Date;
    periodType: CashflowPeriodType;
    onDateChange: (date: Date) => void;
    onPeriodTypeChange: (periodType: CashflowPeriodType) => void;
}

const periodTypeOptions: Array<{
    value: CashflowPeriodType;
    labelKey: string;
}> = [
    { value: 'month', labelKey: 'Month' },
    { value: 'quarter', labelKey: 'Quarter' },
    { value: 'year', labelKey: 'Year' },
];

export function PeriodNavigation({
    currentDate,
    periodType,
    onDateChange,
    onPeriodTypeChange,
}: PeriodNavigationProps) {
    const locale = useLocale();
    const now = new Date();
    const isCurrentPeriod = samePeriod(currentDate, now, periodType);

    const handlePreviousPeriod = () => {
        onDateChange(shiftPeriod(currentDate, periodType, -1));
    };

    const handleNextPeriod = () => {
        onDateChange(shiftPeriod(currentDate, periodType, 1));
    };

    const handleCurrentPeriod = () => {
        onDateChange(now);
    };

    return (
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <ButtonGroup className="w-full sm:w-fit">
                {periodTypeOptions.map((option) => (
                    <Button
                        key={option.value}
                        type="button"
                        variant={
                            periodType === option.value ? 'default' : 'outline'
                        }
                        onClick={() => onPeriodTypeChange(option.value)}
                        className={cn(
                            'flex-1 sm:flex-none',
                            periodType === option.value &&
                                'border-primary bg-primary text-primary-foreground',
                        )}
                    >
                        {__(option.labelKey)}
                    </Button>
                ))}
            </ButtonGroup>

            <ButtonGroup className="w-full sm:w-fit">
                <Button
                    variant="outline"
                    size="icon"
                    onClick={handlePreviousPeriod}
                    aria-label={__('Previous period')}
                >
                    <ChevronLeft className="size-4" />
                </Button>

                <Button
                    onClick={handleCurrentPeriod}
                    variant="outline"
                    className="flex-1 sm:flex-none"
                >
                    {formatPeriodLabel(currentDate, periodType, locale)}
                </Button>

                <Button
                    variant="outline"
                    size="icon"
                    onClick={handleNextPeriod}
                    disabled={isCurrentPeriod}
                    aria-label={__('Next period')}
                >
                    <ChevronRight className="size-4" />
                </Button>
            </ButtonGroup>
        </div>
    );
}

function shiftPeriod(
    date: Date,
    periodType: CashflowPeriodType,
    amount: 1 | -1,
): Date {
    if (periodType === 'quarter') {
        const quarterStart = startOfQuarter(date);

        return amount > 0
            ? addQuarters(quarterStart, 1)
            : subQuarters(quarterStart, 1);
    }

    if (periodType === 'year') {
        const yearStart = startOfYear(date);

        return amount > 0 ? addYears(yearStart, 1) : subYears(yearStart, 1);
    }

    const monthStart = startOfMonth(date);

    return amount > 0 ? addMonths(monthStart, 1) : subMonths(monthStart, 1);
}

function samePeriod(
    left: Date,
    right: Date,
    periodType: CashflowPeriodType,
): boolean {
    if (periodType === 'quarter') {
        return isSameQuarter(left, right);
    }

    if (periodType === 'year') {
        return isSameYear(left, right);
    }

    return isSameMonth(left, right);
}

function formatPeriodLabel(
    date: Date,
    periodType: CashflowPeriodType,
    locale: string,
): string {
    if (periodType === 'quarter') {
        return `${__('Q')}${getQuarter(date)} ${formatDate(date, 'yyyy', locale)}`;
    }

    if (periodType === 'year') {
        return formatDate(date, 'yyyy', locale);
    }

    return formatMonthYear(date, locale);
}

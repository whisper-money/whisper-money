import { Button } from '@/components/ui/button';
import { SharedData } from '@/types';
import { formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { usePage } from '@inertiajs/react';
import { addMonths, isSameMonth, subMonths } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PeriodNavigationProps {
    currentDate: Date;
    onDateChange: (date: Date) => void;
}

export function PeriodNavigation({
    currentDate,
    onDateChange,
}: PeriodNavigationProps) {
    const { locale } = usePage<SharedData>().props;
    const now = new Date();
    const isCurrentMonth = isSameMonth(currentDate, now);

    const handlePrevMonth = () => {
        onDateChange(subMonths(currentDate, 1));
    };

    const handleNextMonth = () => {
        onDateChange(addMonths(currentDate, 1));
    };

    const handleCurrentMonth = () => {
        onDateChange(now);
    };

    return (
        <div className="flex items-center gap-2">
            <Button
                variant="outline"
                size="icon-sm"
                onClick={handlePrevMonth}
                aria-label={__('Previous month')}
            >
                <ChevronLeft className="size-4" />
            </Button>

            <button
                onClick={handleCurrentMonth}
                className="min-w-[140px] rounded-md px-3 py-1.5 text-center text-sm font-medium hover:bg-accent"
            >
                {formatMonthYear(currentDate, locale)}
            </button>

            <Button
                variant="outline"
                size="icon-sm"
                onClick={handleNextMonth}
                disabled={isCurrentMonth}
                aria-label={__('Next month')}
            >
                <ChevronRight className="size-4" />
            </Button>
        </div>
    );
}

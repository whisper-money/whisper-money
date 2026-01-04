import { Button } from '@/components/ui/button';
import { addMonths, format, isSameMonth, subMonths } from 'date-fns';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface PeriodNavigationProps {
    currentDate: Date;
    onDateChange: (date: Date) => void;
}

export function PeriodNavigation({
    currentDate,
    onDateChange,
}: PeriodNavigationProps) {
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
                aria-label="Previous month"
            >
                <ChevronLeft className="size-4" />
            </Button>

            <button
                onClick={handleCurrentMonth}
                className="min-w-[140px] rounded-md px-3 py-1.5 text-center text-sm font-medium hover:bg-accent"
            >
                {format(currentDate, 'MMMM yyyy')}
            </button>

            <Button
                variant="outline"
                size="icon-sm"
                onClick={handleNextMonth}
                disabled={isCurrentMonth}
                aria-label="Next month"
            >
                <ChevronRight className="size-4" />
            </Button>
        </div>
    );
}

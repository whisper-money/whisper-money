import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { useLocale } from '@/hooks/use-locale';
import { formatMonthYear } from '@/utils/date';
import { __ } from '@/utils/i18n';
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
    const locale = useLocale();
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
        <ButtonGroup>
            <Button
                variant="outline"
                size="icon"
                onClick={handlePrevMonth}
                aria-label={__('Previous month')}
            >
                <ChevronLeft className="size-4" />
            </Button>

            <Button onClick={handleCurrentMonth} variant="outline">
                {formatMonthYear(currentDate, locale)}
            </Button>

            <Button
                variant="outline"
                size="icon"
                onClick={handleNextMonth}
                disabled={isCurrentMonth}
                aria-label={__('Next month')}
            >
                <ChevronRight className="size-4" />
            </Button>
        </ButtonGroup>
    );
}

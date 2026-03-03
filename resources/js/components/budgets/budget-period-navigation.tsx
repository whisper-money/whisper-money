import { show } from '@/actions/App/Http/Controllers/BudgetController';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { useLocale } from '@/hooks/use-locale';
import { Budget, BudgetPeriod } from '@/types/budget';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';

interface BudgetPeriodNavigationProps {
    budget: Budget;
    currentPeriod: BudgetPeriod;
    previousPeriod: BudgetPeriod | null;
    nextPeriod: BudgetPeriod | null;
}

export function BudgetPeriodNavigation({
    budget,
    currentPeriod,
    previousPeriod,
    nextPeriod,
}: BudgetPeriodNavigationProps) {
    const locale = useLocale();

    const periodLabel = (() => {
        const start = formatDate(
            currentPeriod.start_date,
            "MMM d, ''yy",
            locale,
        );
        const end = formatDate(currentPeriod.end_date, "MMM d, ''yy", locale);
        return `${start} – ${end}`;
    })();

    const handlePrevious = () => {
        if (!previousPeriod) return;
        router.visit(
            show(budget, { query: { period: previousPeriod.id } }).url,
            { preserveScroll: true },
        );
    };

    const handleNext = () => {
        if (!nextPeriod) return;
        router.visit(show(budget, { query: { period: nextPeriod.id } }).url, {
            preserveScroll: true,
        });
    };

    const handleCurrent = () => {
        // Navigate to the budget without a period param to land on the current period
        router.visit(show(budget).url, { preserveScroll: true });
    };

    return (
        <ButtonGroup>
            <Button
                variant="outline"
                size="icon"
                onClick={handlePrevious}
                disabled={!previousPeriod}
                aria-label={__('Previous period')}
            >
                <ChevronLeft className="size-4" />
            </Button>

            <button
                onClick={handleCurrent}
                className="border border-border bg-background px-3 py-1.5 text-center text-sm font-medium text-nowrap hover:bg-accent focus-visible:relative focus-visible:z-10 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {periodLabel}
            </button>

            <Button
                variant="outline"
                size="icon"
                onClick={handleNext}
                disabled={!nextPeriod}
                aria-label={__('Next period')}
            >
                <ChevronRight className="size-4" />
            </Button>
        </ButtonGroup>
    );
}

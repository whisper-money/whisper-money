import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    matchingQuickPeriod,
    QUICK_PERIODS,
    type QuickPeriod,
    quickPeriodRange,
} from '@/lib/cashflow-block';
import { __ } from '@/utils/i18n';

const QUICK_PERIOD_LABELS: Record<QuickPeriod, string> = {
    month: 'Month',
    quarter: 'Quarter',
    year: 'Year',
};

interface QuickPeriodButtonsProps {
    dateFrom: Date | null;
    dateTo: Date | null;
    onChange: (range: { from: Date; to: Date }) => void;
}

/**
 * Month / Quarter / Year: widen the date filter to the whole calendar period
 * containing the start date (today when there is none). The one matching the
 * current range shows as pressed.
 */
export function QuickPeriodButtons({
    dateFrom,
    dateTo,
    onChange,
}: QuickPeriodButtonsProps) {
    const active = matchingQuickPeriod(dateFrom, dateTo);

    return (
        <ButtonGroup aria-label={__('Quick periods')}>
            {QUICK_PERIODS.map((period) => (
                <Button
                    key={period}
                    type="button"
                    size="sm"
                    variant={active === period ? 'default' : 'outline'}
                    aria-pressed={active === period}
                    data-testid={`filter-quick-period-${period}`}
                    onClick={() =>
                        onChange(
                            quickPeriodRange(period, dateFrom ?? new Date()),
                        )
                    }
                >
                    {__(QUICK_PERIOD_LABELS[period])}
                </Button>
            ))}
        </ButtonGroup>
    );
}

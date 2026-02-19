import { Button } from '@/components/ui/button';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

export type ChartGranularity = 'monthly' | 'daily';

interface ChartGranularityToggleProps {
    value: ChartGranularity;
    onValueChange: (value: ChartGranularity) => void;
    className?: string;
    showTooltip?: boolean;
}

export function ChartGranularityToggle({
    value,
    onValueChange,
    className,
    showTooltip = true,
}: ChartGranularityToggleProps) {
    const label = value === 'monthly' ? __('Monthly') : __('Daily');
    const next: ChartGranularity = value === 'monthly' ? 'daily' : 'monthly';
    const tooltip =
        next === 'daily'
            ? __('Switch to daily view')
            : __('Switch to monthly view');

    const button = (
        <Button
            variant="outline"
            size="sm"
            onClick={() => onValueChange(next)}
            className={cn('text-xs', className)}
        >
            {label}
        </Button>
    );

    if (!showTooltip) {
        return button;
    }

    return (
        <Tooltip>
            <TooltipTrigger asChild>{button}</TooltipTrigger>
            <TooltipContent side="bottom">{tooltip}</TooltipContent>
        </Tooltip>
    );
}

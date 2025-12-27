import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { ChartViewType } from '@/hooks/use-chart-views';
import { cn } from '@/lib/utils';
import { BarChart3, Percent, TrendingUp } from 'lucide-react';

interface ChartViewToggleProps {
    value: ChartViewType;
    onValueChange: (value: ChartViewType) => void;
    availableViews: ChartViewType[];
    className?: string;
}

const viewConfig: Record<
    ChartViewType,
    { icon: React.ElementType; label: string; tooltip: string }
> = {
    stacked: {
        icon: BarChart3,
        label: 'Aggregate',
        tooltip: 'Monthly balance',
    },
    mom: { icon: TrendingUp, label: 'MoM', tooltip: 'Month over month change' },
    mom_percent: {
        icon: Percent,
        label: 'MoM%',
        tooltip: 'Month over month change (%)',
    },
};

export function ChartViewToggle({
    value,
    onValueChange,
    availableViews,
    className,
}: ChartViewToggleProps) {
    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(v) => {
                if (v) onValueChange(v as ChartViewType);
            }}
            variant="outline"
            size="sm"
            className={cn('', className)}
        >
            {availableViews.map((view) => {
                const config = viewConfig[view];
                const Icon = config.icon;
                return (
                    <Tooltip key={view}>
                        <TooltipTrigger asChild>
                            <ToggleGroupItem
                                value={view}
                                aria-label={config.label}
                                className="px-2"
                            >
                                <Icon className="size-3.5" />
                            </ToggleGroupItem>
                        </TooltipTrigger>
                        <TooltipContent side="bottom">
                            {config.tooltip}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </ToggleGroup>
    );
}

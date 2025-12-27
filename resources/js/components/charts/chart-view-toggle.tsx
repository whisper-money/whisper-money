import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ChartViewType } from '@/hooks/use-chart-views';
import { BarChart3, Percent, TrendingUp } from 'lucide-react';

interface ChartViewToggleProps {
    value: ChartViewType;
    onValueChange: (value: ChartViewType) => void;
    availableViews: ChartViewType[];
    className?: string;
}

const viewConfig: Record<
    ChartViewType,
    { icon: React.ElementType; label: string }
> = {
    stacked: { icon: BarChart3, label: 'Accounts' },
    mom: { icon: TrendingUp, label: 'MoM' },
    mom_percent: { icon: Percent, label: 'MoM%' },
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
            className={className}
        >
            {availableViews.map((view) => {
                const config = viewConfig[view];
                const Icon = config.icon;
                return (
                    <ToggleGroupItem
                        key={view}
                        value={view}
                        aria-label={config.label}
                        className="gap-1.5 px-2"
                    >
                        <Icon className="size-3.5" />
                        <span className="hidden sm:inline">{config.label}</span>
                    </ToggleGroupItem>
                );
            })}
        </ToggleGroup>
    );
}

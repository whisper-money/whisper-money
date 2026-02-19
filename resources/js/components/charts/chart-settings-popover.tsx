import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ChartViewType } from '@/hooks/use-chart-views';
import { __ } from '@/utils/i18n';
import { Settings2 } from 'lucide-react';
import {
    type ChartGranularity,
    ChartGranularityToggle,
} from './chart-granularity-toggle';
import { ChartViewToggle } from './chart-view-toggle';

interface ChartSettingsPopoverProps {
    granularity: ChartGranularity;
    onGranularityChange: (value: ChartGranularity) => void;
    currentView: ChartViewType;
    onViewChange: (value: ChartViewType) => void;
    availableViews: ChartViewType[];
}

export function ChartSettingsPopover({
    granularity,
    onGranularityChange,
    currentView,
    onViewChange,
    availableViews,
}: ChartSettingsPopoverProps) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="outline" size="icon" className="size-8">
                    <Settings2 className="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-auto">
                <div className="flex flex-col gap-3">
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm font-medium">
                            {__('Period')}
                        </span>
                        <ChartGranularityToggle
                            value={granularity}
                            onValueChange={onGranularityChange}
                            showTooltip={false}
                        />
                    </div>
                    <div className="flex items-center justify-between gap-4">
                        <span className="text-sm font-medium">
                            {__('Chart type')}
                        </span>
                        <ChartViewToggle
                            value={currentView}
                            onValueChange={onViewChange}
                            availableViews={availableViews}
                            granularity={granularity}
                            showTooltip={false}
                        />
                    </div>
                </div>
            </PopoverContent>
        </Popover>
    );
}

import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { ChartViewType } from '@/hooks/use-chart-views';
import { __ } from '@/utils/i18n';
import { Settings2 } from 'lucide-react';
import { Separator } from '../ui/separator';
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
    showChartControls?: boolean;
    includeLoansLabel?: string;
    includeLoans?: boolean;
    onIncludeLoansChange?: (value: boolean) => void;
}

export function ChartSettingsPopover({
    granularity,
    onGranularityChange,
    currentView,
    onViewChange,
    availableViews,
    showChartControls = true,
    includeLoansLabel,
    includeLoans,
    onIncludeLoansChange,
}: ChartSettingsPopoverProps) {
    return (
        <Popover>
            <PopoverTrigger asChild>
                <Button variant="outline" size="icon" className="size-8">
                    <Settings2 className="size-4" />
                </Button>
            </PopoverTrigger>
            <PopoverContent align="end" className="w-72">
                <div className="flex flex-col gap-3">
                    {showChartControls ? (
                        <>
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
                            <Separator />
                        </>
                    ) : null}
                    {onIncludeLoansChange &&
                    typeof includeLoans === 'boolean' &&
                    includeLoansLabel ? (
                        <>
                            <div className="flex items-start justify-between gap-4">
                                <div className="space-y-1">
                                    <Label
                                        htmlFor="include-loans-in-net-worth-chart"
                                        className="text-sm leading-5 font-medium"
                                    >
                                        {includeLoansLabel}
                                    </Label>
                                    <p className="text-xs text-muted-foreground">
                                        {__(
                                            'Include loan balances in the net worth totals and chart',
                                        )}
                                    </p>
                                </div>
                                <Checkbox
                                    id="include-loans-in-net-worth-chart"
                                    checked={includeLoans}
                                    onCheckedChange={(checked) =>
                                        onIncludeLoansChange(checked === true)
                                    }
                                    className="mt-0.5"
                                />
                            </div>
                        </>
                    ) : null}
                </div>
            </PopoverContent>
        </Popover>
    );
}

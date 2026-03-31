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

interface ToggleOption {
    id: string;
    label: string;
    description: string;
    checked: boolean;
    onChange: (value: boolean) => void;
}

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
    toggles?: ToggleOption[];
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
    toggles = [],
}: ChartSettingsPopoverProps) {
    // Build the effective list of toggles: legacy loan prop + explicit toggles
    const allToggles: ToggleOption[] = [];

    if (
        onIncludeLoansChange &&
        typeof includeLoans === 'boolean' &&
        includeLoansLabel
    ) {
        allToggles.push({
            id: 'include-loans-in-net-worth-chart',
            label: includeLoansLabel,
            description: __(
                'Include loan balances in the net worth totals and chart',
            ),
            checked: includeLoans,
            onChange: onIncludeLoansChange,
        });
    }

    allToggles.push(...toggles);

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
                            {allToggles.length > 0 && <Separator />}
                        </>
                    ) : null}
                    {allToggles.map((toggle) => (
                        <div
                            key={toggle.id}
                            className="flex cursor-pointer items-start justify-between gap-4"
                            onClick={() => toggle.onChange(!toggle.checked)}
                        >
                            <div className="space-y-1">
                                <Label
                                    htmlFor={toggle.id}
                                    className="pointer-events-none text-sm leading-5 font-medium"
                                >
                                    {toggle.label}
                                </Label>
                                <p className="pointer-events-none text-xs text-muted-foreground">
                                    {toggle.description}
                                </p>
                            </div>
                            <Checkbox
                                id={toggle.id}
                                checked={toggle.checked}
                                onCheckedChange={(checked) =>
                                    toggle.onChange(checked === true)
                                }
                                className="mt-0.5"
                            />
                        </div>
                    ))}
                </div>
            </PopoverContent>
        </Popover>
    );
}

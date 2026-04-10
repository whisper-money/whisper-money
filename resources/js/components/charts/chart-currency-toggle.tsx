import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

export type ChartCurrencyMode = 'account' | 'user';

interface ChartCurrencyToggleProps {
    value: ChartCurrencyMode;
    onValueChange: (value: ChartCurrencyMode) => void;
    accountCurrencyCode: string;
    userCurrencyCode: string;
    className?: string;
    showTooltip?: boolean;
}

export function ChartCurrencyToggle({
    value,
    onValueChange,
    accountCurrencyCode,
    userCurrencyCode,
    className,
    showTooltip = true,
}: ChartCurrencyToggleProps) {
    const items: Array<{
        mode: ChartCurrencyMode;
        label: string;
        tooltip: string;
    }> = [
        {
            mode: 'user',
            label: userCurrencyCode,
            tooltip: __('Show in your currency (:currency)', {
                currency: userCurrencyCode,
            }),
        },
        {
            mode: 'account',
            label: accountCurrencyCode,
            tooltip: __('Show in account currency (:currency)', {
                currency: accountCurrencyCode,
            }),
        },
    ];

    return (
        <ToggleGroup
            type="single"
            value={value}
            onValueChange={(v) => {
                if (v) onValueChange(v as ChartCurrencyMode);
            }}
            variant="outline"
            size="sm"
            className={cn(className)}
        >
            {items.map((item) => {
                const toggleItem = (
                    <ToggleGroupItem
                        key={item.mode}
                        value={item.mode}
                        aria-label={item.tooltip}
                        className="cursor-pointer px-2 text-xs aria-checked:bg-primary/10"
                    >
                        {item.label}
                    </ToggleGroupItem>
                );

                if (!showTooltip) {
                    return toggleItem;
                }

                return (
                    <Tooltip key={item.mode}>
                        <TooltipTrigger asChild>{toggleItem}</TooltipTrigger>
                        <TooltipContent side="bottom">
                            {item.tooltip}
                        </TooltipContent>
                    </Tooltip>
                );
            })}
        </ToggleGroup>
    );
}

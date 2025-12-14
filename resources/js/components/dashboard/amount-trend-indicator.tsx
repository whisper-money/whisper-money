import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface AmountTrendIndicatorProps {
    trend: number;
    isPositive: boolean;
    label: string;
    className?: string;
    previousAmount?: number;
    currentAmount?: number;
    tooltipSide?: 'top' | 'right' | 'bottom' | 'left';
    currencyCode?: string;
}

function calculatePercentageChange(
    previousAmount: number,
    currentAmount: number,
): number | null {
    if (previousAmount === 0) return null;
    return ((currentAmount - previousAmount) / Math.abs(previousAmount)) * 100;
}

export function AmountTrendIndicator({
    trend,
    isPositive,
    label,
    className = '',
    previousAmount,
    currentAmount,
    tooltipSide = 'top',
    currencyCode = 'USD',
}: AmountTrendIndicatorProps) {
    const [open, setOpen] = useState(false);

    const Icon = isPositive ? TrendingUp : TrendingDown;
    const iconColorClass = isPositive
        ? 'text-green-600/70 dark:text-green-400/70'
        : 'text-red-600/70 dark:text-red-400/70';

    const percentageChange =
        previousAmount !== undefined && currentAmount !== undefined
            ? calculatePercentageChange(previousAmount, currentAmount)
            : null;

    const content = (
        <div className={cn(['flex items-center gap-1', className])}>
            <span
                className={
                    isPositive ? 'bg-green-100/25 dark:bg-green-900/25' : ''
                }
            >
                <AmountDisplay
                    amountInCents={trend}
                    currencyCode={currencyCode}
                />
            </span>
            <span className="text-muted-foreground">{label}</span>
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );

    if (percentageChange !== null) {
        return (
            <Tooltip open={open} onOpenChange={setOpen}>
                <TooltipTrigger asChild>
                    <div
                        className="cursor-default"
                        onClick={() => setOpen((prev) => !prev)}
                    >
                        {content}
                    </div>
                </TooltipTrigger>
                <TooltipContent side={tooltipSide}>
                    {percentageChange >= 0 ? '+' : ''}
                    {percentageChange.toFixed(1)}% {label}
                </TooltipContent>
            </Tooltip>
        );
    }

    return content;
}

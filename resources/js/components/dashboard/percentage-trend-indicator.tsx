import { AmountDisplay } from '@/components/ui/amount-display';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from 'lucide-react';
import { useState } from 'react';

interface PercentageTrendIndicatorProps {
    trend: number | null;
    label: string;
    previousAmount?: number;
    currentAmount?: number;
    currencyCode?: string;
    invertColors?: boolean;
    className?: string;
    tooltipSide?: 'top' | 'right' | 'bottom' | 'left';
}

export function PercentageTrendIndicator({
    trend,
    label,
    previousAmount,
    currentAmount,
    currencyCode = 'USD',
    invertColors = false,
    className,
    tooltipSide = 'top',
}: PercentageTrendIndicatorProps) {
    const [open, setOpen] = useState(false);

    if (trend === null) return null;

    const isPositive = trend >= 0;
    const isGood = invertColors ? !isPositive : isPositive;
    const Icon = isPositive ? TrendingUp : TrendingDown;
    const iconColorClass = isGood
        ? 'text-green-600/70 dark:text-green-400/70'
        : 'text-red-600/70 dark:text-red-400/70';

    const amountDiff =
        previousAmount !== undefined && currentAmount !== undefined
            ? currentAmount - previousAmount
            : null;

    const content = (
        <div className={cn(['flex items-center gap-1', className])}>
            <span>
                {isPositive ? '+' : ''}
                {trend.toFixed(1)}%
            </span>
            {label && <span className="text-muted-foreground">{label}</span>}
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );

    if (amountDiff !== null) {
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
                    {amountDiff >= 0 ? '+' : '-'}
                    <AmountDisplay
                        amountInCents={Math.abs(amountDiff)}
                        currencyCode={currencyCode}
                        variant="trend"
                        highlightPositive={amountDiff >= 0}
                        minimumFractionDigits={0}
                        maximumFractionDigits={0}
                    />{' '}
                    {label}
                </TooltipContent>
            </Tooltip>
        );
    }

    return content;
}

import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from 'lucide-react';

interface PercentageTrendIndicatorProps {
    trend: number | null;
    label: string;
    previousAmount?: number;
    currentAmount?: number;
    currencyCode?: string;
    invertColors?: boolean;
    className?: string;
}

function formatCurrency(amount: number, currencyCode: string): string {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: currencyCode,
        minimumFractionDigits: 0,
        maximumFractionDigits: 0,
    }).format(amount / 100);
}

export function PercentageTrendIndicator({
    trend,
    label,
    previousAmount,
    currentAmount,
    currencyCode = 'USD',
    invertColors = false,
    className,
}: PercentageTrendIndicatorProps) {
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
            <span
                className={isGood ? 'bg-green-100/25 dark:bg-green-900/25' : ''}
            >
                {isPositive ? '+' : ''}
                {trend.toFixed(1)}%
            </span>
            {label && <span className="text-muted-foreground">{label}</span>}
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );

    if (amountDiff !== null) {
        const formattedDiff = formatCurrency(
            Math.abs(amountDiff),
            currencyCode,
        );
        return (
            <Tooltip>
                <TooltipTrigger asChild>
                    <div className="cursor-default">{content}</div>
                </TooltipTrigger>
                <TooltipContent>
                    {amountDiff >= 0 ? '+' : '-'}
                    {formattedDiff} {label}
                </TooltipContent>
            </Tooltip>
        );
    }

    return content;
}

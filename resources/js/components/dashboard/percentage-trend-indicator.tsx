import { TrendingUp, TrendingDown } from "lucide-react";

export function PercentageTrendIndicator({
    trend,
    label,
}: {
    trend: number | null;
    label: string;
}) {
    if (trend === null) return null;

    const isPositive = trend >= 0;
    const Icon = isPositive ? TrendingUp : TrendingDown;
    const iconColorClass = isPositive
        ? 'text-green-600/70 dark:text-green-400/70'
        : 'text-red-600/70 dark:text-red-400/70';

    return (
        <div className="flex items-center gap-1">
            <span
                className={
                    isPositive ? 'bg-green-100/25 dark:bg-green-900/25' : ''
                }
            >
                {isPositive ? '+' : ''}
                {trend.toFixed(1)}%
            </span>
            <span className="text-muted-foreground">{label}</span>
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );
}

import { cn } from '@/lib/utils';
import { TrendingDown, TrendingUp } from 'lucide-react';

export function AmountTrendIndicator({
    trend,
    isPositive,
    label,
    className = '',
}: {
    trend: string | null;
    isPositive: boolean;
    label: string;
    className?: string;
}) {
    if (trend === null) return null;

    const Icon = isPositive ? TrendingUp : TrendingDown;
    const iconColorClass = isPositive
        ? 'text-green-600/70 dark:text-green-400/70'
        : 'text-red-600/70 dark:text-red-400/70';

    return (
        <div className={cn(['flex items-center gap-1', className])}>
            <span
                className={
                    isPositive ? 'bg-green-100/25 dark:bg-green-900/25' : ''
                }
            >
                {trend}
            </span>
            <span className="text-muted-foreground">{label}</span>
            <Icon className={`h-4 w-4 ${iconColorClass}`} />
        </div>
    );
}

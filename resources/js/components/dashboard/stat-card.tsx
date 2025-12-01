import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { ArrowDownIcon, ArrowUpIcon, LucideIcon } from 'lucide-react';

interface StatCardProps {
    title: string;
    value: string;
    description?: string;
    icon?: LucideIcon;
    trend?: {
        value: number;
        label: string;
    };
    className?: string;
    loading?: boolean;
}

export function StatCard({
    title,
    value,
    description,
    icon: Icon,
    trend,
    className,
    loading,
}: StatCardProps) {
    if (loading) {
        return (
            <Card className={className}>
                <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                    <CardTitle className="text-sm font-medium">
                        {title}
                    </CardTitle>
                    {Icon && <Icon className="text-muted-foreground size-4" />}
                </CardHeader>
                <CardContent>
                    <div className="h-8 w-32 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                    <div className="mt-1 h-4 w-24 animate-pulse rounded bg-gray-200 dark:bg-gray-700" />
                </CardContent>
            </Card>
        );
    }

    return (
        <Card className={className}>
            <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                <CardTitle className="text-sm font-medium">{title}</CardTitle>
                {Icon && <Icon className="text-muted-foreground size-4" />}
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-bold">{value}</div>
                {(description || trend) && (
                    <div className="text-muted-foreground flex items-center text-xs">
                        {trend && (
                            <span
                                className={cn(
                                    'mr-2 flex items-center font-medium',
                                    trend.value > 0
                                        ? 'text-green-600'
                                        : trend.value < 0
                                          ? 'text-red-600'
                                          : '',
                                )}
                            >
                                {trend.value > 0 ? (
                                    <ArrowUpIcon className="mr-1 size-3" />
                                ) : trend.value < 0 ? (
                                    <ArrowDownIcon className="mr-1 size-3" />
                                ) : null}
                                {Math.abs(trend.value).toFixed(1)}%
                            </span>
                        )}
                        {trend?.label || description}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}



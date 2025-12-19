import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { formatCurrency } from '@/utils/currency';
import { HelpCircle } from 'lucide-react';
import { useMemo } from 'react';

interface Props {
    totalIncome: number;
    totalAllocated: number;
    carriedOver: number;
}

export function AvailableToAssignCard({
    totalIncome,
    totalAllocated,
    carriedOver,
}: Props) {
    const available = totalIncome + carriedOver - totalAllocated;

    const statusColor = useMemo(() => {
        if (available < 0) return 'text-red-600 dark:text-red-400';
        if (available === 0) return 'text-gray-600 dark:text-gray-400';
        return 'text-green-600 dark:text-green-400';
    }, [available]);

    const statusBg = useMemo(() => {
        if (available < 0) return 'bg-red-50 dark:bg-red-950/20';
        if (available === 0) return 'bg-gray-50 dark:bg-gray-950/20';
        return 'bg-green-50 dark:bg-green-950/20';
    }, [available]);

    return (
        <Card className={statusBg}>
            <CardHeader>
                <div className="flex items-center justify-between">
                    <CardTitle className="text-lg">
                        Money Available to Assign
                    </CardTitle>
                    <TooltipProvider>
                        <Tooltip>
                            <TooltipTrigger>
                                <HelpCircle className="h-4 w-4 text-muted-foreground" />
                            </TooltipTrigger>
                            <TooltipContent className="max-w-xs">
                                <p className="text-sm">
                                    This is the amount of money you have
                                    available to allocate to your budget
                                    categories. It includes your income for this
                                    period, plus any carried over amounts, minus
                                    what you've already allocated.
                                </p>
                            </TooltipContent>
                        </Tooltip>
                    </TooltipProvider>
                </div>
                <CardDescription>
                    {available < 0
                        ? 'You have over-allocated your budget'
                        : available === 0
                          ? 'All money has been assigned'
                          : 'Assign this money to your categories'}
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className="space-y-4">
                    <div className={`text-4xl font-bold ${statusColor}`}>
                        {formatCurrency(available)}
                    </div>

                    <div className="space-y-2 border-t pt-4">
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">
                                Income this period
                            </span>
                            <span>{formatCurrency(totalIncome)}</span>
                        </div>
                        {carriedOver !== 0 && (
                            <div className="flex items-center justify-between text-sm">
                                <span className="text-muted-foreground">
                                    Carried over
                                </span>
                                <span>{formatCurrency(carriedOver)}</span>
                            </div>
                        )}
                        <div className="flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">
                                Already allocated
                            </span>
                            <span>-{formatCurrency(totalAllocated)}</span>
                        </div>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}


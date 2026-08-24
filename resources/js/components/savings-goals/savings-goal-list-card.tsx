import { show } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { useLocale } from '@/hooks/use-locale';
import {
    getSavingsGoalStatusColor,
    getSavingsGoalStatusLabel,
    SavingsGoal,
} from '@/types/savings-goal';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRight, Target } from 'lucide-react';

interface Props {
    savingsGoal: SavingsGoal;
    currencyCode: string;
}

export function SavingsGoalListCard({ savingsGoal, currencyCode }: Props) {
    const locale = useLocale();
    const stats = savingsGoal.stats;
    const percentage = stats?.percentage ?? 0;
    const saved = stats?.saved ?? 0;
    const status = stats?.status ?? null;

    const statusColor = status
        ? getSavingsGoalStatusColor(status)
        : 'text-muted-foreground';

    return (
        <Card>
            <CardHeader>
                <div className="flex items-start justify-between">
                    <div className="space-y-1">
                        <CardTitle className="text-xl">
                            <Link
                                href={show({ savingsGoal: savingsGoal.id }).url}
                                className="-my-1 -ml-1.5 inline-flex items-center rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
                            >
                                {savingsGoal.name}
                            </Link>
                        </CardTitle>
                        {savingsGoal.target_date && (
                            <CardDescription className="flex items-center gap-2">
                                <Target className="h-3 w-3" />
                                {__('By :date', {
                                    date: formatDate(
                                        savingsGoal.target_date,
                                        'MMM d, yyyy',
                                        locale,
                                    ),
                                })}
                            </CardDescription>
                        )}
                    </div>
                    {status && (
                        <Badge variant="outline" className={statusColor}>
                            {getSavingsGoalStatusLabel(status)}
                        </Badge>
                    )}
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-2">
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {__('Saved')}
                        </span>
                        <span className={statusColor}>
                            <AmountDisplay
                                amountInCents={saved}
                                currencyCode={currencyCode}
                            />{' '}
                            {__('of')}{' '}
                            <AmountDisplay
                                amountInCents={savingsGoal.target_amount}
                                currencyCode={currencyCode}
                            />
                        </span>
                    </div>
                    <Progress
                        value={Math.min(Math.max(percentage, 0), 100)}
                        className="h-2"
                    />
                    <div className="text-right text-sm text-muted-foreground">
                        {Math.max(0, Math.round(percentage))}%
                    </div>
                </div>

                <div className="flex items-center justify-end border-t pt-4">
                    <Link href={show({ savingsGoal: savingsGoal.id }).url}>
                        <Button
                            className="cursor-pointer"
                            variant="ghost"
                            size="sm"
                        >
                            {__('View Details')}
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Button>
                    </Link>
                </div>
            </CardContent>
        </Card>
    );
}

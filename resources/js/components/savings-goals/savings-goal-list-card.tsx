import { show } from '@/actions/App/Http/Controllers/SavingsGoalController';
import { PlanningCard } from '@/components/shared/planning-card';
import { AmountDisplay } from '@/components/ui/amount-display';
import { Badge } from '@/components/ui/badge';
import { useLocale } from '@/hooks/use-locale';
import {
    getSavingsGoalStatusColor,
    getSavingsGoalStatusLabel,
    SavingsGoal,
} from '@/types/savings-goal';
import { formatDate } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Archive, Target } from 'lucide-react';
import { ReactNode } from 'react';

const RING_SIZE = 64;
const RING_STROKE = 6;
const RING_RADIUS = (RING_SIZE - RING_STROKE) / 2;
const RING_CIRCUMFERENCE = 2 * Math.PI * RING_RADIUS;

function ProgressRing({ percentage }: { percentage: number }) {
    return (
        <div className="relative h-16 w-16 shrink-0">
            <svg
                viewBox={`0 0 ${RING_SIZE} ${RING_SIZE}`}
                className="h-16 w-16 -rotate-90"
                aria-hidden="true"
            >
                <circle
                    cx={RING_SIZE / 2}
                    cy={RING_SIZE / 2}
                    r={RING_RADIUS}
                    fill="none"
                    strokeWidth={RING_STROKE}
                    className="stroke-secondary"
                />
                <circle
                    cx={RING_SIZE / 2}
                    cy={RING_SIZE / 2}
                    r={RING_RADIUS}
                    fill="none"
                    strokeWidth={RING_STROKE}
                    strokeLinecap="round"
                    className="stroke-primary"
                    strokeDasharray={`${(RING_CIRCUMFERENCE * percentage) / 100} ${RING_CIRCUMFERENCE}`}
                />
            </svg>
            <div className="absolute inset-0 flex items-center justify-center text-sm font-semibold tabular-nums">
                {Math.round(percentage)}%
            </div>
        </div>
    );
}

interface Props {
    savingsGoal: SavingsGoal;
    currencyCode: string;
    dragHandle?: ReactNode;
}

export function SavingsGoalListCard({
    savingsGoal,
    currencyCode,
    dragHandle,
}: Props) {
    const locale = useLocale();
    const stats = savingsGoal.stats;
    const percentage = Math.min(Math.max(stats?.percentage ?? 0, 0), 100);
    const saved = stats?.saved ?? 0;
    const toGo = Math.max(savingsGoal.target_amount - saved, 0);
    const status = stats?.status ?? null;
    const archivedAt = savingsGoal.archived_at;

    const statusColor = status
        ? getSavingsGoalStatusColor(status)
        : 'text-muted-foreground';

    return (
        <PlanningCard
            href={show({ savingsGoal: savingsGoal.id }).url}
            title={savingsGoal.name}
            dimmed={!!archivedAt}
            dragHandle={dragHandle}
            badge={
                archivedAt ? (
                    <Badge variant="secondary">{__('Archived')}</Badge>
                ) : status ? (
                    <Badge variant="outline" className={statusColor}>
                        {getSavingsGoalStatusLabel(status)}
                    </Badge>
                ) : undefined
            }
            description={
                archivedAt ? (
                    <>
                        <Archive className="h-3 w-3" />
                        {__('Archived on :date', {
                            date: formatDate(archivedAt, 'MMM d, yyyy', locale),
                        })}
                    </>
                ) : savingsGoal.target_date ? (
                    <>
                        <Target className="h-3 w-3" />
                        {__('By :date', {
                            date: formatDate(
                                savingsGoal.target_date,
                                'MMM d, yyyy',
                                locale,
                            ),
                        })}
                    </>
                ) : undefined
            }
        >
            {/* A savings goal fills up, so it reads as a ring closing rather
                than the draining bar the budget card uses. That difference is
                what tells the two apart in the mixed list. */}
            <div className="flex items-center gap-4">
                <ProgressRing percentage={percentage} />
                <div className="flex flex-1 flex-col gap-2">
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
                    <div className="flex items-center justify-between text-sm">
                        <span className="text-muted-foreground">
                            {__('To go')}
                        </span>
                        <AmountDisplay
                            amountInCents={toGo}
                            currencyCode={currencyCode}
                        />
                    </div>
                </div>
            </div>
        </PlanningCard>
    );
}

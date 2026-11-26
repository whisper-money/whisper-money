import { Progress } from '@/components/ui/progress';
import { cn } from '@/lib/utils';
import { type AchievementsProgress } from '@/types';
import { __ } from '@/utils/i18n';

/** "Progress  40 / 59" over a bar of the medals unlocked so far. */
export function UserMenuProgress({
    achievements,
    className,
    barClassName,
}: {
    achievements: AchievementsProgress;
    className?: string;
    barClassName?: string;
}) {
    const percent =
        achievements.total > 0
            ? (achievements.unlocked / achievements.total) * 100
            : 0;

    return (
        <span className={cn('flex flex-1 flex-col gap-1.5', className)}>
            <span className="flex justify-between">
                <span>{__('Progress')}</span>
                <span className="text-muted-foreground tabular-nums">
                    {achievements.unlocked} / {achievements.total}
                </span>
            </span>
            <Progress
                aria-hidden="true"
                className={barClassName}
                value={percent}
            />
        </span>
    );
}

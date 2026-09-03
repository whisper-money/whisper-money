import { AchievementFigure } from '@/components/achievements/achievement-figure';
import { Medal } from '@/components/achievements/medal';
import { RarityTag } from '@/components/achievements/rarity-tag';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type AchievementMedal } from '@/types';
import { __ } from '@/utils/i18n';

/**
 * One medal in a track.
 *
 * Earned, it shows the milestone, what it is called and the month it really
 * happened. Still to come, it is a silhouette with three question marks: the
 * server does not send its name, so there is nothing here to read ahead.
 *
 * The two read apart without colour: an earned cell is filled and bordered,
 * an empty one is dashed and transparent, and the epic tier alone gets a solid
 * foreground border.
 */
export function monthLabel(date: string, locale: string): string {
    const [year, month] = date.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString(locale, {
        month: 'short',
        year: 'numeric',
    });
}

export function AchievementCell({ medal }: { medal: AchievementMedal }) {
    const locale = useLocale();

    return (
        <div
            className={cn(
                'flex w-32 shrink-0 flex-col gap-2 rounded-lg border p-2.5',
                medal.locked ? 'min-h-24 border-dashed' : 'min-h-31 bg-card',
                !medal.locked && medal.rarity === 'epic' && 'border-foreground',
            )}
        >
            <Medal
                rarity={medal.rarity}
                icon={medal.icon}
                locked={medal.locked}
            />

            <div className="flex flex-1 flex-col gap-px">
                {medal.locked ? (
                    <span className="font-mono text-sm font-semibold tracking-widest text-muted-foreground">
                        ???
                    </span>
                ) : (
                    <>
                        {medal.figure && (
                            <AchievementFigure
                                figure={medal.figure}
                                className="text-sm leading-[18px] font-semibold"
                            />
                        )}
                        <span
                            className={cn(
                                'text-xs leading-4 text-pretty',
                                medal.figure
                                    ? 'text-muted-foreground'
                                    : 'font-medium',
                            )}
                        >
                            {medal.name}
                        </span>
                        {medal.achieved_on && (
                            <span className="mt-0.5 text-[11px] leading-[14px] text-muted-foreground tabular-nums">
                                {monthLabel(medal.achieved_on, locale)}
                            </span>
                        )}
                    </>
                )}
            </div>

            <RarityTag
                rarity={medal.rarity}
                share={medal.locked ? null : medal.share}
                muted={medal.locked}
                title={
                    medal.share === null
                        ? undefined
                        : __(':share% of members have this one', {
                              share: medal.share,
                          })
                }
            />
        </div>
    );
}

import { AchievementFigure } from '@/components/achievements/achievement-figure';
import { EPIC_EDGE, Medal } from '@/components/achievements/medal';
import { RarityTag } from '@/components/achievements/rarity-tag';
import { ShareMedalDialog } from '@/components/achievements/share-medal-dialog';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type AchievementMedal } from '@/types';
import { __ } from '@/utils/i18n';
import { Share2Icon } from 'lucide-react';

/**
 * One medal in a track.
 *
 * Earned, it shows the milestone, what it is called and the month it really
 * happened. Still to come, it is a silhouette with three question marks: the
 * server does not send its name, so there is nothing here to read ahead.
 *
 * The two read apart without colour: an earned cell is filled and bordered, an
 * empty one is dashed and transparent. The epic tier alone gets a border of its
 * own, struck in the gold of the crown its medal wears.
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
            )}
            style={
                !medal.locked && medal.rarity === 'epic'
                    ? { borderColor: EPIC_EDGE }
                    : undefined
            }
        >
            <div className="flex items-start justify-between gap-1">
                <Medal
                    rarity={medal.rarity}
                    icon={medal.icon}
                    locked={medal.locked}
                />

                {/* Always drawn rather than revealed on hover: the phone is
                    where a medal actually gets posted, and there is no hover
                    there to reveal it with. */}
                {!medal.locked && (
                    <ShareMedalDialog medal={medal}>
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label={__('Share this medal')}
                            className="-mt-1 -mr-1 size-7 cursor-pointer text-muted-foreground"
                        >
                            <Share2Icon className="size-3.5" />
                        </Button>
                    </ShareMedalDialog>
                )}
            </div>

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

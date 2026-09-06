import { AchievementFigure } from '@/components/achievements/achievement-figure';
import { EPIC_EDGE, Medal } from '@/components/achievements/medal';
import { RarityTag } from '@/components/achievements/rarity-tag';
import { ShareMedalDialog } from '@/components/achievements/share-medal-dialog';
import { Button } from '@/components/ui/button';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type AchievementMedal, type AchievementProgress } from '@/types';
import { __ } from '@/utils/i18n';
import { Share2Icon } from 'lucide-react';

/**
 * One medal in a track.
 *
 * Earned, it shows the milestone, what it is called and the month it really
 * happened. Still to come, it is a silhouette with three question marks: the
 * server does not send its name, so there is nothing here to read ahead.
 *
 * The exception is the next one of its track, which arrives named and with the
 * figure to reach — a ladder of identical question marks says nothing about
 * what there is to chase. It is drawn as a medal turned down rather than as one
 * won: the same dashed, transparent slot, with the real medallion dimmed inside
 * it. Nothing labels it "next", because in every track there is exactly one
 * cell with something in it between the earned ones and the question marks, and
 * the position already says so.
 *
 * The states read apart without colour: an earned cell is filled and bordered,
 * the other two are dashed and transparent. The epic tier alone gets a border
 * of its own, struck in the gold of the crown its medal wears.
 */
export function monthLabel(date: string, locale: string): string {
    const [year, month] = date.split('-').map(Number);

    return new Date(year, month - 1, 1).toLocaleDateString(locale, {
        month: 'short',
        year: 'numeric',
    });
}

/**
 * The one progress bar in the app, shared with the overview at the top of the
 * screen so the two cannot drift apart.
 */
export function ProgressBar({
    percent,
    className,
}: {
    percent: number;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'h-1.5 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className="h-full rounded-full bg-primary"
                style={{ width: `${percent}%` }}
            />
        </div>
    );
}

/**
 * How far along the next medal is, for the tracks that can say.
 *
 * Past the goal with the medal still locked is the everyday case rather than an
 * edge one — crossing day 30 of a visit streak happens by opening the app, and
 * the sweep that awards it runs at night — so the bar fills and says when it
 * lands instead of showing a count that reads as wrong either way it is
 * written.
 */
function MedalProgress({ progress }: { progress: AchievementProgress }) {
    const percent = progress.unlocking
        ? 100
        : Math.round((progress.now / progress.goal) * 100);

    return (
        <div className="mt-1.5 flex flex-col gap-[3px]">
            <ProgressBar percent={percent} />
            <span className="text-[11px] leading-[14px] text-muted-foreground tabular-nums">
                {progress.unlocking
                    ? __('Unlocks tonight')
                    : __(':now of :goal', {
                          // Written like the figure above it rather than as a
                          // bare integer: "4,321 of 10,000", not "4321 of 10000".
                          now: progress.now.toLocaleString(),
                          goal: progress.goal.toLocaleString(),
                      })}
            </span>
        </div>
    );
}

export function AchievementCell({ medal }: { medal: AchievementMedal }) {
    const locale = useLocale();
    const earned = medal.state === 'earned';
    const next = medal.state === 'next';
    const locked = medal.state === 'locked';

    return (
        <div
            className={cn(
                'flex w-32 shrink-0 flex-col gap-2 rounded-lg border p-2.5',
                earned && 'min-h-31 bg-card',
                next && 'min-h-31 border-dashed',
                locked && 'min-h-24 border-dashed',
            )}
            style={
                earned && medal.rarity === 'epic'
                    ? { borderColor: EPIC_EDGE }
                    : undefined
            }
        >
            <div className="flex items-start justify-between gap-1">
                <Medal
                    rarity={medal.rarity}
                    icon={medal.icon}
                    locked={locked}
                    // Struck but not yet won: the real medal, turned down, so
                    // its shape and pictogram still read as "not yet" rather
                    // than as a failure.
                    className={next ? 'opacity-50 grayscale-[0.7]' : undefined}
                />

                {/* Always drawn rather than revealed on hover: the phone is
                    where a medal actually gets posted, and there is no hover
                    there to reveal it with. */}
                {earned && (
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
                {locked ? (
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
                        {medal.progress && (
                            <MedalProgress progress={medal.progress} />
                        )}
                    </>
                )}
            </div>

            <RarityTag
                rarity={medal.rarity}
                share={earned ? medal.share : null}
                muted={!earned}
                title={
                    earned && medal.share !== null
                        ? __(':share% of members have this one', {
                              share: medal.share,
                          })
                        : undefined
                }
            />
        </div>
    );
}

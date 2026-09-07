import { AchievementCell } from '@/components/achievements/achievement-cell';
import { Medal } from '@/components/achievements/medal';
import { cn } from '@/lib/utils';
import { type AchievementMedal } from '@/types';
import { __ } from '@/utils/i18n';
import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * The rungs of one track: what has been earned, what is next, and one slot
 * standing for everything still to come.
 *
 * The catalogue is fifty-nine medals across thirteen tracks, so on day one the
 * ladders were fifty-nine question marks — the wall of failure they were drawn
 * to avoid, just laid out sideways. The locked tail is folded into a single
 * slot instead, and it folds for every reader rather than only for newcomers:
 * a veteran loses nothing, because the rest of their ladder is one click away
 * and no round trip to the server is needed to open it.
 */
export function TrackMedals({ medals }: { medals: AchievementMedal[] }) {
    const [expanded, setExpanded] = useState(false);
    const locked = medals.filter((medal) => medal.state === 'locked');
    const shown = expanded
        ? medals
        : medals.filter((medal) => medal.state !== 'locked');

    return (
        <div className="flex gap-2 sm:flex-wrap">
            {shown.map((medal) => (
                <AchievementCell key={medal.key} medal={medal} />
            ))}

            {locked.length > 0 && (
                <button
                    type="button"
                    onClick={() => setExpanded(!expanded)}
                    className="flex w-26 shrink-0 cursor-pointer flex-col items-center justify-center gap-2 self-stretch rounded-lg border border-dashed border-ring p-3"
                >
                    {/* Three rings rather than N: a stack says "more of these"
                        at any count, and the count is written underneath. */}
                    <span className="flex items-center">
                        {[0, 1, 2].map((index) => (
                            <Medal
                                key={index}
                                // The locked ring is the same silhouette in
                                // every tier, so nothing here reads the rarity.
                                rarity="common"
                                locked
                                size={26}
                                className={index > 0 ? '-ml-2.5' : undefined}
                            />
                        ))}
                    </span>

                    <span className="flex items-center gap-0.5 text-[11px] leading-[14px] font-medium text-muted-foreground tabular-nums">
                        {expanded
                            ? __('Show less')
                            : __('+:count to come', { count: locked.length })}
                        <ChevronRightIcon
                            className={cn('size-3.5', expanded && 'rotate-180')}
                        />
                    </span>
                </button>
            )}
        </div>
    );
}

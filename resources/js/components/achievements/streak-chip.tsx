import {
    MedalProgress,
    progressPercent,
} from '@/components/achievements/achievement-cell';
import {
    AchievementFigure,
    daysLabel,
} from '@/components/achievements/achievement-figure';
import { Medal } from '@/components/achievements/medal';
import { AdaptivePopover } from '@/components/ui/adaptive-popover';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { index as progressScreen } from '@/routes/achievements';
import { type ChallengeMedal, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRightIcon, FlameIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * The visit streak, in the header, on every screen.
 *
 * The number is the run as it stands today, not the longest one ever: a streak
 * with nothing to lose is a trophy, and a trophy is not a reason to come back
 * tomorrow. The ring around it is a different measurement on purpose — how far
 * the *medal* is, which the progress screen measures on the longest run so the
 * two screens cannot disagree. When those two numbers differ the panel says so
 * rather than leaving the reader to work out which is which.
 *
 * Three states, and the pill has to read as itself in all three:
 *
 * 1. Mid-run: a ring filling towards the next rung.
 * 2. Standing on the rung with the medal still locked — the sweep runs at night
 *    — so the real medal takes the ring's place and the pill fills in.
 * 3. Past the last rung: nothing left to aim at, so the ring is simply full and
 *    the number keeps climbing.
 *
 * Not drawn at all without a live run, or with the feature switched off.
 */
const RING_RADIUS = 10;
const RING_LENGTH = 2 * Math.PI * RING_RADIUS;

function StreakRing({ percent }: { percent: number }) {
    return (
        <span className="relative inline-flex size-[22px] shrink-0 items-center justify-center md:size-6">
            <svg viewBox="0 0 24 24" className="size-full" aria-hidden>
                <g transform="rotate(-90 12 12)">
                    <circle
                        cx="12"
                        cy="12"
                        r={RING_RADIUS}
                        fill="none"
                        stroke="var(--border)"
                        strokeWidth="2.5"
                    />
                    <circle
                        cx="12"
                        cy="12"
                        r={RING_RADIUS}
                        fill="none"
                        stroke="var(--foreground)"
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeDasharray={`${(percent / 100) * RING_LENGTH} ${RING_LENGTH}`}
                    />
                </g>
            </svg>
            <FlameIcon className="absolute size-[11px] md:size-3" />
        </span>
    );
}

/**
 * One track in the panel: the medal turned down inside a dashed slot, the same
 * treatment the progress screen gives a rung nobody has reached yet.
 */
function PanelRow({
    medal,
    isMobile,
}: {
    medal: ChallengeMedal;
    isMobile: boolean;
}) {
    return (
        <div className="flex items-center gap-3 py-3">
            <span
                className="flex shrink-0 items-center justify-center rounded-[10px] border border-dashed border-ring"
                style={{
                    width: isMobile ? 48 : 44,
                    height: isMobile ? 48 : 44,
                }}
            >
                <Medal
                    rarity={medal.rarity}
                    icon={medal.icon}
                    size={isMobile ? 36 : 32}
                    className="opacity-50 grayscale-[0.7]"
                />
            </span>
            <div className="flex min-w-0 flex-1 flex-col">
                <AchievementFigure
                    figure={medal.figure}
                    className="text-[13px] leading-4 font-semibold"
                />
                <span className="text-xs leading-4 text-muted-foreground">
                    {medal.name}
                </span>
                {medal.progress && (
                    <MedalProgress progress={medal.progress} className="mt-1" />
                )}
            </div>
        </div>
    );
}

export function StreakChip({ className }: { className?: string }) {
    const { challenges } = usePage<SharedData>().props;
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);

    if (!challenges || challenges.visit_streak < 1) {
        return null;
    }

    const streak = challenges.visit_streak;
    const visits =
        challenges.medals.find((medal) => medal.track === 'visits') ?? null;
    // No next rung left means the track is finished: the ring is full and there
    // is no goal to fall short of.
    const percent = visits?.progress ? progressPercent(visits.progress) : 100;
    const unlocking = visits?.progress?.unlocking ?? false;
    // What the bars below are measured on. Only worth spelling out when it has
    // pulled ahead of the live run, which is exactly when the two disagree.
    const best = visits?.progress?.now ?? null;

    const trigger = (
        <button
            type="button"
            aria-label={__('Visit streak: :days', { days: daysLabel(streak) })}
            className={cn(
                // The phone needs 44px of pushable area around a 30px pill, and
                // padding that big would wreck the pill: `ui/sidebar` solves the
                // same problem the same way.
                'relative inline-flex h-[30px] cursor-pointer items-center gap-[7px] rounded-full border pr-[11px] pl-[5px] transition-colors md:h-8',
                'after:absolute after:-inset-2 md:after:hidden',
                'hover:border-ring hover:bg-muted',
                unlocking && 'bg-muted',
                className,
            )}
        >
            {unlocking && visits ? (
                <Medal
                    rarity={visits.rarity}
                    icon={visits.icon}
                    size={isMobile ? 22 : 24}
                />
            ) : (
                <StreakRing percent={percent} />
            )}
            <span className="text-[13px] leading-none font-semibold tabular-nums">
                {streak}
            </span>
        </button>
    );

    const panel = (
        <div className="flex flex-col px-4 py-3.5">
            <div className="flex flex-col gap-0.5 pb-3">
                <span className="text-[13px] leading-4 font-semibold">
                    {daysLabel(streak)}
                </span>
                <span className="text-xs leading-4 text-muted-foreground">
                    {__('Current visit streak')}
                </span>
                {best !== null && best > streak && (
                    <span className="mt-1 text-[11px] leading-[14px] text-pretty text-muted-foreground">
                        {__('The medals below count your best run: :days', {
                            days: daysLabel(best),
                        })}
                    </span>
                )}
            </div>

            {challenges.medals.map((medal) => (
                <div key={medal.track} className="border-t">
                    <PanelRow medal={medal} isMobile={isMobile} />
                </div>
            ))}

            <Link
                href={progressScreen()}
                onClick={() => setOpen(false)}
                className={cn(
                    'flex items-center justify-center gap-1 border-t pt-3 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground',
                    isMobile && 'mt-3 h-11 rounded-md border pt-0',
                )}
            >
                {__('View all progress')}
                <ArrowRightIcon className="size-3.5" />
            </Link>
        </div>
    );

    return (
        <AdaptivePopover
            open={open}
            onOpenChange={setOpen}
            trigger={trigger}
            title={__('Visit streak')}
            className="w-[320px]"
            openOnHover
        >
            {panel}
        </AdaptivePopover>
    );
}

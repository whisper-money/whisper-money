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
import { readOwnedValue, writeOwnedValue } from '@/lib/safe-storage';
import { cn } from '@/lib/utils';
import { index as progressScreen } from '@/routes/achievements';
import { type ChallengeMedal, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRightIcon, FlameIcon } from 'lucide-react';
import { useEffect, useId, useRef, useState, type CSSProperties } from 'react';

/*
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
 * 2. Standing on the rung with the medal still locked, so the real medal takes
 *    the ring's place. Rare now that a visit medal is awarded on the request
 *    that earns it: what is left is the reader the sweep has never been
 *    through, whose first pass is the one that reads a whole life.
 * 3. Past the last rung: nothing left to aim at, so the ring is simply full and
 *    the number keeps climbing.
 *
 * Not drawn at all without a live run, or with the feature switched off.
 *
 * The skin is warm — fill, border, ring and number all come from the
 * `--streak-*` tokens — and the flame is alive: it flickers over a halo that
 * breathes, and both stop dead for a reader who asked for less motion. The
 * medal in state 2 keeps its own metal; only the pill around it is tinted.
 */

const RING_RADIUS = 10;
const RING_LENGTH = 2 * Math.PI * RING_RADIUS;

function StreakRing({
    percent,
    celebrating,
    entering,
}: {
    percent: number;
    celebrating: boolean;
    entering: boolean;
}) {
    // Same treatment `medal.tsx` gives its own gradients: a hand-written id
    // would be shared by every ring on the page.
    const gradient = useId();
    const struck = (percent / 100) * RING_LENGTH;

    return (
        <span className="relative inline-flex size-[22px] shrink-0 items-center justify-center md:size-6">
            <span
                aria-hidden
                className={cn(
                    'absolute -inset-0.5 rounded-full bg-[radial-gradient(circle,var(--streak-to)_0%,transparent_68%)] opacity-40',
                    celebrating ? 'streak-halo-burst' : 'streak-halo',
                )}
            />
            <svg viewBox="0 0 24 24" className="size-full" aria-hidden>
                <defs>
                    <linearGradient id={gradient} x1="0" y1="0" x2="1" y2="1">
                        {/* `style`, not the `stop-color` attribute: Safari
                            does not substitute `var()` inside an SVG
                            presentation attribute and the ring comes out
                            black. */}
                        <stop
                            offset="0"
                            style={{ stopColor: 'var(--streak-from)' }}
                        />
                        <stop
                            offset="1"
                            style={{ stopColor: 'var(--streak-to)' }}
                        />
                    </linearGradient>
                </defs>
                <g transform="rotate(-90 12 12)">
                    <circle
                        cx="12"
                        cy="12"
                        r={RING_RADIUS}
                        fill="none"
                        stroke="var(--streak-track)"
                        strokeWidth="2.5"
                    />
                    <circle
                        className={cn(entering && 'streak-ring')}
                        // The arc draws itself in from nothing: the keyframes
                        // walk `stroke-dashoffset` down from the struck length
                        // to zero, which leaves the dash array itself alone.
                        style={{ '--streak-dash': struck } as CSSProperties}
                        cx="12"
                        cy="12"
                        r={RING_RADIUS}
                        fill="none"
                        stroke={`url(#${gradient})`}
                        strokeWidth="2.5"
                        strokeLinecap="round"
                        strokeDasharray={`${struck} ${RING_LENGTH}`}
                    />
                </g>
            </svg>
            <FlameIcon
                className={cn(
                    'absolute size-[11px] fill-current stroke-none text-[var(--streak-to)] md:size-3',
                    celebrating ? 'streak-flame-flare' : 'streak-flame',
                )}
            />
        </span>
    );
}

/*
 * Whether the run has grown since this reader last looked.
 *
 * Nothing on the server says "the streak advanced": `TrackLastActiveAt` carries
 * the run forward silently and the shared props only ever state today's number.
 * So the last number the reader saw is remembered here, on the device, keyed by
 * user — see `readOwnedValue`, which is why another account's number reads as
 * nothing rather than as a rise.
 *
 * Per device on purpose: opening the app on the phone after the laptop replays
 * the moment. That is the right side to err on for something that only ever
 * adds a flourish, and it buys a celebration that survives a hard refresh
 * without a column on the users table to carry it.
 *
 * No number stored at all — the first visit on this device — means no
 * celebration. Cheering a run the reader has been on for weeks would be a lie.
 */
const STREAK_SEEN_KEY = 'streak-seen';

/*
 * Whether this is the first time the pill has been drawn since the page loaded.
 *
 * "On mount" is not the same thing here: no screen keeps the header across an
 * Inertia visit, so the whole chrome remounts on every click and a plain mount
 * animation would replay the entrance every time the reader opens a screen.
 * Module scope is exactly the lifetime wanted instead — it outlives a
 * client-side visit and dies with the page — so the entrance lands once per
 * full load, which is what the design asks for.
 */
let entered = false;

function useEntrance(): boolean {
    const [entering] = useState(() => !entered);

    useEffect(() => {
        entered = true;
    }, []);

    return entering;
}

function useStreakRaised(userId: string, streak: number | null): boolean {
    const [raised, setRaised] = useState(false);
    // The effect reads what it is about to overwrite, so running it twice for
    // the same number answers "no rise" the second time and swallows the
    // celebration. StrictMode does exactly that in development, and an Inertia
    // navigation that remounts the header would do it in production. Recording
    // the number already handled makes the second pass a no-op while still
    // letting a genuinely new number through.
    const recorded = useRef<number | null>(null);

    useEffect(() => {
        if (streak === null || recorded.current === streak) {
            return;
        }

        recorded.current = streak;

        const seen = readOwnedValue(STREAK_SEEN_KEY, userId);

        setRaised(seen !== null && streak > Number(seen));
        writeOwnedValue(STREAK_SEEN_KEY, userId, String(streak));
    }, [userId, streak]);

    return raised;
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
    const { auth, challenges } = usePage<SharedData>().props;
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    // Above the early return, so the number the reader is looking at is
    // recorded even on the renders that draw no pill at all.
    const celebrating = useStreakRaised(
        auth.user.id,
        challenges ? challenges.visit_streak : null,
    );
    const entering = useEntrance();

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
                // `streak-chip` is what the reduced-motion rule in app.css
                // hangs off: it silences every animation inside the pill.
                'streak-chip relative inline-flex h-[30px] cursor-pointer items-center gap-[7px] rounded-full border border-[var(--streak-border)] bg-[var(--streak-fill)] pr-[11px] pl-[5px] transition-colors md:h-8',
                'after:absolute after:-inset-2 md:after:hidden',
                'hover:border-[var(--streak-to)]',
                entering && 'animate-in duration-500 fade-in-0 zoom-in-95',
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
                <StreakRing
                    percent={percent}
                    celebrating={celebrating}
                    entering={entering}
                />
            )}
            <span
                className={cn(
                    'text-[13px] leading-none font-semibold text-[var(--streak-ink)] tabular-nums',
                    celebrating && 'streak-count-pop',
                )}
            >
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

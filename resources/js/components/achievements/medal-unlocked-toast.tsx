import { AchievementFigure } from '@/components/achievements/achievement-figure';
import { Medal } from '@/components/achievements/medal';
import { useChallengesToast } from '@/components/achievements/use-challenges-toast';
import { readOwnedValue, writeOwnedValue } from '@/lib/safe-storage';
import { index as progressScreen } from '@/routes/achievements';
import { type ChallengeMedal, type Challenges } from '@/types';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { ArrowRightIcon } from 'lucide-react';
import { useCallback } from 'react';
import { toast } from 'sonner';

/*
 * "You just earned a medal" — the one toast that announces rather than asks.
 *
 * Visit medals are settled the moment the run reaches the rung, on the request
 * that moves it, so by the time a screen renders the medal is already on the
 * shelf. Something has to say so out loud: a row quietly appearing in the bell
 * is a record, not a moment, and the run is the one thing in the app the reader
 * earned by simply turning up.
 *
 * Ten seconds and dismissible, unlike the categorize prompt next door: that one
 * asks for work and waits to be acted on, this one is over once it has been
 * read.
 */
const TOAST_ID = 'medal-unlocked';

/**
 * The last medal announced on this device, keyed by reader.
 *
 * Same bargain the streak pill makes, and for the same reason: the server knows
 * the medal was awarded but not whether this reader has been told, and a column
 * to carry "have you seen it" would be a migration and a write on the request
 * path for something purely cosmetic. Nothing stored yet means nothing is
 * announced — a shelf full of old medals is not news, and greeting a new device
 * with a medal from March would be a lie.
 */
const MEDAL_SEEN_KEY = 'medal-seen';

let shown = false;

function MedalUnlockedToastBody({ medal }: { medal: ChallengeMedal }) {
    return (
        <div className="flex w-full items-center gap-3 rounded-lg border bg-popover p-3.5 text-popover-foreground shadow-lg">
            <span className="relative flex size-12 shrink-0 items-center justify-center">
                <span
                    aria-hidden
                    className="medal-burst absolute inset-0 rounded-full bg-[radial-gradient(circle,var(--streak-to)_0%,transparent_70%)]"
                />
                {/* The sweep of light rides on its own clipped layer: the medal
                    itself is an SVG of gradients and must not be masked. */}
                <span className="medal-strike relative flex size-12 items-center justify-center overflow-hidden rounded-full">
                    <Medal rarity={medal.rarity} icon={medal.icon} size={44} />
                    <span
                        aria-hidden
                        className="medal-shine absolute -inset-x-2 inset-y-0 bg-linear-to-r from-transparent via-white/70 to-transparent"
                    />
                </span>
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-[13px] leading-4 font-semibold">
                    {__('Medal unlocked')}
                </span>
                <span className="text-xs leading-4 text-muted-foreground">
                    {medal.name}
                </span>
                {medal.figure && (
                    <AchievementFigure
                        figure={medal.figure}
                        className="text-[13px] leading-4 font-semibold text-[var(--streak-ink)]"
                    />
                )}
            </div>

            <Link
                href={progressScreen()}
                onClick={() => toast.dismiss(TOAST_ID)}
                className="flex shrink-0 items-center gap-1 self-center rounded-md px-2 py-1 text-[13px] font-medium text-muted-foreground transition-colors hover:text-foreground"
            >
                {__('See it')}
                <ArrowRightIcon className="size-3.5" />
            </Link>
        </div>
    );
}

/** Whether this medal is news to this reader, recording it either way. */
function isNews(medal: ChallengeMedal, userId: string): boolean {
    const seen = readOwnedValue(MEDAL_SEEN_KEY, userId);

    writeOwnedValue(MEDAL_SEEN_KEY, userId, medal.key);

    return seen !== null && seen !== medal.key;
}

function show(challenges: Challenges | null, userId: string | undefined): void {
    const medal = challenges?.unlocked ?? null;

    if (shown || medal === null || userId === undefined) {
        return;
    }

    shown = true;

    if (!isNews(medal, userId)) {
        return;
    }

    // A tick late, for the same reason the categorize prompt is: sonner keeps
    // no backlog, and `<Toaster/>` subscribes from an effect of its own, so a
    // toast raised while the shell is still mounting is broadcast to nobody.
    queueMicrotask(() =>
        toast.custom(() => <MedalUnlockedToastBody medal={medal} />, {
            id: TOAST_ID,
            duration: 10000,
        }),
    );
}

/**
 * Mounted once in the shell, beside the toaster and the categorize prompt.
 */
export function MedalUnlockedToast({
    initialChallenges,
    userId,
}: {
    initialChallenges: Challenges | null;
    userId: string | undefined;
}) {
    // Stable, or the hook would drop and re-take its navigation subscription
    // on every render of the shell.
    const fire = useCallback(
        (challenges: Challenges | null) => show(challenges, userId),
        [userId],
    );

    useChallengesToast(initialChallenges, fire);

    return null;
}

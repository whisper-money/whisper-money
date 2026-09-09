import { MedalProgress } from '@/components/achievements/achievement-cell';
import { monthsLabel } from '@/components/achievements/achievement-figure';
import { useChallengesToast } from '@/components/achievements/use-challenges-toast';
import { snooze } from '@/routes/achievements/uncategorized';
import { index as transactionsIndex } from '@/routes/transactions';
import { type Challenges } from '@/types';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { endOfMonth, format, startOfMonth } from 'date-fns';
import { TagsIcon } from 'lucide-react';
import { toast } from 'sonner';

/**
 * "You have transactions with no category" — the one nudge that asks for work
 * rather than announcing something.
 *
 * Persistent on purpose: no timer, no close button, no swipe. It goes away by
 * being acted on or by being put away for three days, and putting it away is
 * recorded on the user so the phone honours what was said on the laptop.
 *
 * One per session. It is fired from the shell rather than from a page, so
 * without the guard every navigation would stack another copy of the same ask.
 */
const TOAST_ID = 'uncategorized-transactions';

let shown = false;

/**
 * Straight to the pile it is asking about, and to nothing else: the count is
 * the month in progress, so the list has to be the month in progress too. An
 * unbounded "uncategorized" filter would open on a longer list than the number
 * the reader just clicked.
 */
function categorizeHref(): string {
    const today = new Date();

    return transactionsIndex({
        query: {
            category_ids: 'uncategorized',
            date_from: format(startOfMonth(today), 'yyyy-MM-dd'),
            date_to: format(endOfMonth(today), 'yyyy-MM-dd'),
        },
    }).url;
}

function UncategorizedToastBody({
    prompt,
}: {
    prompt: NonNullable<Challenges['uncategorized']>;
}) {
    const progress = prompt.medal?.progress ?? null;

    return (
        <div className="flex w-full gap-3 rounded-lg border bg-popover p-3.5 text-popover-foreground shadow-lg">
            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted">
                <TagsIcon className="size-[17px]" />
            </span>

            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="text-[13px] leading-4 font-medium">
                    {__(':count uncategorized transactions', {
                        count: prompt.count,
                    })}
                </span>
                <span className="text-[13px] leading-[17px] text-pretty text-muted-foreground">
                    {__(
                        'A month with everything categorized is a month you can actually read.',
                    )}
                </span>

                {progress && (
                    <MedalProgress
                        progress={progress}
                        goalLabel={monthsLabel(progress.goal)}
                        className="mt-0.5"
                    />
                )}

                <div className="mt-2 flex items-center gap-2">
                    <Link
                        href={categorizeHref()}
                        onClick={() => toast.dismiss(TOAST_ID)}
                        className="inline-flex h-[26px] items-center rounded-md bg-primary px-2.5 text-xs font-medium text-primary-foreground transition-opacity hover:opacity-90"
                    >
                        {__('Categorize')}
                    </Link>
                    <button
                        type="button"
                        onClick={() => {
                            toast.dismiss(TOAST_ID);
                            // Nothing to do with the answer, and nothing to
                            // show if it never lands: the toast is already gone
                            // and the next page load asks again.
                            void axios.post(snooze.url()).catch(() => {});
                        }}
                        className="inline-flex h-[26px] cursor-pointer items-center rounded-md px-2 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground"
                    >
                        {__('Not now')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function show(challenges: Challenges | null | undefined): void {
    if (shown || !challenges?.uncategorized) {
        return;
    }

    shown = true;

    const prompt = challenges.uncategorized;

    // Published a tick late, on purpose. Sonner hands a new toast straight to
    // whoever is subscribed and keeps no backlog, and `<Toaster/>` subscribes
    // from an effect of its own — so a toast raised while the shell is still
    // mounting is broadcast to nobody and lost, silently and for good, because
    // the latch above has already been thrown. Sibling effects flush in the
    // order the components appear, which would make this work or not depending
    // on where in `app.tsx` this one sits; a microtask runs after the whole
    // flush instead, so the position stops mattering.
    queueMicrotask(() =>
        toast.custom(() => <UncategorizedToastBody prompt={prompt} />, {
            id: TOAST_ID,
            duration: Infinity,
            dismissible: false,
        }),
    );
}

/**
 * Mounted once in the shell, beside the toaster. Fires on the first page that
 * carries the prompt — the props are lazy, so the very first render of a hard
 * reload already has them — and re-checks on navigation for the reader whose
 * first screen had nothing to categorize.
 */
export function UncategorizedToast({
    initialChallenges,
}: {
    initialChallenges: Challenges | null;
}) {
    useChallengesToast(initialChallenges, show);

    return null;
}

import { type Challenges, type SharedData } from '@/types';
import { router } from '@inertiajs/react';
import { useEffect } from 'react';

/**
 * Fires a challenges toast from the shell: once on the first page, and again on
 * every visit for the reader whose first screen had nothing to say.
 *
 * The shell is where these belong rather than a page — every screen carries the
 * props — but the shell is also mounted once and never again, so the navigation
 * subscription is the whole of the second half. `show` is expected to hold its
 * own latch; this only decides when to ask.
 */
export function useChallengesToast(
    initialChallenges: Challenges | null,
    show: (challenges: Challenges | null) => void,
): void {
    useEffect(() => {
        show(initialChallenges);

        return router.on('navigate', (event) => {
            const pageProps = event.detail.page.props as unknown as SharedData;

            show(pageProps.challenges);
        });
    }, [initialChallenges, show]);
}

import { type Challenges } from '@/types';
import { render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { Toaster } from 'sonner';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/*
 * The prompt that asks a reader to categorize the month.
 *
 * The one thing worth pinning down here is when it is published. Sonner hands a
 * new toast straight to whoever is subscribed and keeps no backlog, and its
 * `<Toaster/>` subscribes from an effect — so raising a toast from a sibling
 * that happens to sit earlier in the shell loses it outright, with the
 * once-per-session latch already thrown. That is not a hypothetical: it is
 * exactly how this shipped the first time, and it is invisible without a test,
 * because nothing throws and nothing logs.
 *
 * So the component is mounted here in the order that used to break it — before
 * the toaster, the way `app.tsx` lays the shell out — and the toast still has to
 * arrive.
 */

vi.mock('@inertiajs/react', () => ({
    router: { on: () => () => {} },
    Link: ({ children }: { children: ReactNode }) => <a>{children}</a>,
}));

vi.mock('axios', () => ({
    default: { post: vi.fn(() => Promise.resolve({ data: {} })) },
}));

function challenges(uncategorized: Challenges['uncategorized']): Challenges {
    return { visit_streak: 3, medals: [], unlocked: null, uncategorized };
}

/**
 * The shell, in `app.tsx`'s own order: the toast component first, the toaster
 * after it. Re-imported per test because the once-per-session latch is module
 * state.
 */
async function mountShell(props: Challenges | null) {
    const { UncategorizedToast } = await import('./uncategorized-toast');

    return render(
        <>
            <UncategorizedToast initialChallenges={props} />
            <Toaster />
        </>,
    );
}

describe('UncategorizedToast', () => {
    beforeEach(() => {
        vi.resetModules();
    });

    it('reaches the toaster even though it is mounted before it', async () => {
        await mountShell(
            challenges({
                count: 4,
                medal: {
                    key: 'categorized.4',
                    track: 'categorized',
                    rarity: 'epic',
                    icon: 'tags',
                    name: 'Fully categorized',
                    figure: { type: 'months', value: 24, currency: null },
                    progress: { now: 12, goal: 24, unlocking: false },
                },
            }),
        );

        expect(
            await screen.findByText('4 uncategorized transactions'),
        ).toBeInTheDocument();
        // The bar reads the medal the work feeds, written with its unit so the
        // number is not a bare "12 of 24" floating over a pile of transactions.
        expect(await screen.findByText('12 of 24 months')).toBeInTheDocument();
        expect(screen.getByText('Categorize')).toBeInTheDocument();
        expect(screen.getByText('Not now')).toBeInTheDocument();
    });

    it('says nothing when there is nothing to categorize', async () => {
        await mountShell(challenges(null));

        // Give the publish the same tick it would have had.
        await Promise.resolve();

        expect(screen.queryByText('Categorize')).toBeNull();
    });
});

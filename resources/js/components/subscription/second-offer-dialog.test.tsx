import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    post: vi.fn(() => Promise.resolve({})),
    showSecondOffer: { current: true },
    latam: { current: false },
    backlog: {
        current: null as null | {
            count: number;
            recent: {
                id: string;
                description: string;
                amount: number;
                currency_code: string;
            }[];
        },
    },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('axios', () => ({ default: { post: mocks.post } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            showSecondOffer: mocks.showSecondOffer.current,
            secondOfferBacklog: mocks.backlog.current,
            pricing: {
                ...pricing,
                ...(mocks.latam.current
                    ? {
                          latam: {
                              plans: pricing.plans,
                              timezones: null,
                          },
                          offeredTier: 'latam',
                      }
                    : {}),
            },
            auth: { user: { id: 'user-1' } },
            locale: 'en',
        },
    }),
}));

/**
 * A fresh copy of the component each time: it latches itself open once per
 * page load, and that latch lives at module level.
 */
async function renderDialog() {
    vi.resetModules();
    const { SecondOfferDialog } = await import('./second-offer-dialog');

    return render(<SecondOfferDialog />);
}

describe('SecondOfferDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.showSecondOffer.current = true;
        mocks.latam.current = false;
        mocks.backlog.current = {
            count: 214,
            recent: [
                {
                    id: 't1',
                    description: 'MERCADONA VALENCIA',
                    amount: -4820,
                    currency_code: 'EUR',
                },
                {
                    id: 't2',
                    description: 'NETFLIX.COM',
                    amount: -1399,
                    currency_code: 'EUR',
                },
                {
                    id: 't3',
                    description: 'REPSOL E.S. 2281',
                    amount: -6105,
                    currency_code: 'EUR',
                },
            ],
        };
    });

    it('opens for an eligible user, records it as shown and tracks it', async () => {
        await renderDialog();

        expect(
            screen.getByText('214 of your movements need a category'),
        ).toBeInTheDocument();
        expect(mocks.post).toHaveBeenCalledWith(
            '/subscribe/second-offer/shown',
        );
        expect(mocks.captureEvent).toHaveBeenCalledWith('second_offer_shown', {
            price_tier: 'standard',
        });
    });

    it('starts checkout attributed to the second offer', async () => {
        await renderDialog();

        expect(
            screen
                .getByRole('link', { name: 'Sort them — free for 14 days' })
                .getAttribute('href'),
        ).toContain('source=second_offer');
    });

    it('shows the latest uncategorized movements as they are, without guessing a category', async () => {
        await renderDialog();

        expect(screen.getByText('Waiting for a category')).toBeInTheDocument();
        expect(screen.getByText('MERCADONA VALENCIA')).toBeInTheDocument();
        expect(screen.getByText('NETFLIX.COM')).toBeInTheDocument();
        expect(screen.getByText('REPSOL E.S. 2281')).toBeInTheDocument();
        expect(screen.getByText('-€48.20')).toBeInTheDocument();
        expect(screen.getAllByText('Needs a category')).toHaveLength(3);
        // No AI ran yet: nothing is guessed for them.
        expect(screen.queryByText(/Groceries|Subscriptions/)).toBeNull();
    });

    it('says what leaves the account, since its checkout switches the AI on', async () => {
        await renderDialog();

        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Merchant names and amounts, one line at a time/),
        ).toBeInTheDocument();
    });

    it('keeps the disclosure when there is no backlog to preview', async () => {
        mocks.backlog.current = { count: 0, recent: [] };
        await renderDialog();

        expect(
            screen.getByText('What leaves your account'),
        ).toBeInTheDocument();
    });

    it('speaks of a single movement in the singular', async () => {
        mocks.backlog.current = {
            count: 1,
            recent: mocks.backlog.current!.recent.slice(0, 1),
        };
        await renderDialog();

        expect(
            screen.getByText('1 of your movements needs a category'),
        ).toBeInTheDocument();
    });

    it('drops the backlog copy and the preview once nothing is waiting', async () => {
        mocks.backlog.current = { count: 0, recent: [] };
        await renderDialog();

        expect(screen.getByText('Your movements are in')).toBeInTheDocument();
        expect(screen.queryByText('Waiting for a category')).toBeNull();
        expect(
            screen.getByText('And the rest of Standard'),
        ).toBeInTheDocument();
    });

    it('keeps the backlog it opened with once the next visit shares none', async () => {
        const { rerender } = await renderDialog();
        const { SecondOfferDialog } = await import('./second-offer-dialog');

        // The "shown" stamp makes the user ineligible: the next props carry
        // no offer and no backlog, while the dialog is still open.
        mocks.showSecondOffer.current = false;
        mocks.backlog.current = null;
        rerender(<SecondOfferDialog />);

        expect(
            screen.getByText('214 of your movements need a category'),
        ).toBeInTheDocument();
        expect(screen.getByText('MERCADONA VALENCIA')).toBeInTheDocument();
    });

    it('promises a bank sync to a user offered the standard price', async () => {
        await renderDialog();

        expect(screen.getByText(/keeps your bank in sync/)).toBeInTheDocument();
    });

    it('never promises a bank sync to a user offered the latam price', async () => {
        mocks.latam.current = true;
        await renderDialog();

        expect(screen.queryByText(/keeps your bank in sync/)).toBeNull();
        expect(
            screen.getByText(/keeps your direct integrations in sync/),
        ).toBeInTheDocument();
        expect(mocks.captureEvent).toHaveBeenCalledWith('second_offer_shown', {
            price_tier: 'latam',
        });
    });

    it('stays closed when the user is not eligible', async () => {
        mocks.showSecondOffer.current = false;
        await renderDialog();

        expect(
            screen.queryByText('214 of your movements need a category'),
        ).toBeNull();
        expect(mocks.post).not.toHaveBeenCalled();
        expect(mocks.captureEvent).not.toHaveBeenCalled();
    });

    it('closes on "Maybe later" and does not open again in the same page load', async () => {
        vi.resetModules();
        const { SecondOfferDialog } = await import('./second-offer-dialog');
        const { unmount } = render(<SecondOfferDialog />);

        fireEvent.click(screen.getByRole('button', { name: 'Maybe later' }));

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'second_offer_dismissed',
            { price_tier: 'standard' },
        );

        // The next visit remounts the shell before the server knows it was
        // shown; the latch keeps it shut.
        unmount();
        render(<SecondOfferDialog />);

        expect(mocks.post).toHaveBeenCalledTimes(1);
    });
});

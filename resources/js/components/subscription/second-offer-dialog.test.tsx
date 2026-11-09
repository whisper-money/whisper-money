import { pricingFixture as pricing } from '@/lib/pricing-fixture';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const mocks = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    post: vi.fn(() => Promise.resolve({})),
    showSecondOffer: { current: true },
    latam: { current: false },
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('axios', () => ({ default: { post: mocks.post } }));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({
        props: {
            showSecondOffer: mocks.showSecondOffer.current,
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
    });

    it('opens for an eligible user, records it as shown and tracks it', async () => {
        await renderDialog();

        expect(
            screen.getByText('Your transactions are in'),
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
                .getByRole('link', { name: /Start a plan/ })
                .getAttribute('href'),
        ).toContain('source=second_offer');
    });

    it('promises a bank sync to a user offered the standard price', async () => {
        await renderDialog();

        expect(screen.getByText(/sync your bank/)).toBeInTheDocument();
    });

    it('never promises a bank sync to a user offered the latam price', async () => {
        mocks.latam.current = true;
        await renderDialog();

        expect(screen.queryByText(/sync your bank/)).toBeNull();
        expect(
            screen.getByText(/keep your direct integrations in sync/),
        ).toBeInTheDocument();
        expect(mocks.captureEvent).toHaveBeenCalledWith('second_offer_shown', {
            price_tier: 'latam',
        });
    });

    it('stays closed when the user is not eligible', async () => {
        mocks.showSecondOffer.current = false;
        await renderDialog();

        expect(screen.queryByText('Your transactions are in')).toBeNull();
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

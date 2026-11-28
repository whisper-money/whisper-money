import { render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { type PriceTierChoice } from '@/hooks/use-price-tier';
import { type Plan } from '@/types/pricing';

import Welcome from './welcome';

const plan = (features: string[]): Plan => ({
    name: 'Monthly',
    price: 5,
    original_price: null,
    stripe_lookup_key: 'monthly',
    billing_period: 'month',
    trial_days: 0,
    features,
});

const standardPlans = {
    monthly: plan([
        'Connect bank accounts',
        'AI Suggestions',
        'Custom dashboards with AI-built widgets',
        'Unlimited accounts',
    ]),
};

// The latam price leaves bank connections out (`HandleInertiaRequests::latamPricing`).
const latamPlans = {
    monthly: plan([
        'AI Suggestions',
        'Custom dashboards with AI-built widgets',
        'Unlimited accounts',
    ]),
};

const tierChoice = vi.hoisted(() => ({
    current: null as unknown as PriceTierChoice,
}));

vi.mock('@/hooks/use-price-tier', () => ({
    usePriceTierChoice: () => tierChoice.current,
}));

// jsdom ships no `matchMedia`, which the page's PWA and media checks read.
window.matchMedia ??= (query: string) =>
    ({
        matches: false,
        media: query,
        addEventListener: () => {},
        removeEventListener: () => {},
    }) as unknown as MediaQueryList;

// Nor `IntersectionObserver`, which the hero video and scroll reveals use.
globalThis.IntersectionObserver ??= class {
    observe() {}
    unobserve() {}
    disconnect() {}
    takeRecords() {
        return [];
    }
} as unknown as typeof IntersectionObserver;

// jsdom cannot play media; the hero is not what these tests look at.
vi.mock('@/components/landing/hero-video', () => ({ HeroVideo: () => null }));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
    router: { visit: vi.fn(), on: () => () => {} },
    usePage: () => ({
        url: '/',
        props: {
            appUrl: 'https://whisper.money',
            subscriptionsEnabled: true,
            demoEnabled: false,
            locale: 'en-US',
            translations: {},
            auth: { user: null },
            pricing: {
                plans: standardPlans,
                defaultPlan: 'monthly',
                bestValuePlan: null,
                promo: { enabled: false, code: '', description: '', badge: '' },
                currency: 'USD',
                latam: { plans: latamPlans, timezones: [] },
            },
        },
    }),
}));

function renderWithTier(tier: 'latam' | 'standard') {
    tierChoice.current = {
        tier,
        plans: tier === 'latam' ? latamPlans : standardPlans,
        canSwitch: tier === 'latam',
        setTier: vi.fn(),
        isPending: false,
    };

    render(
        <Welcome
            canRegister
            popularBanks={[]}
            comparisonLinks={[]}
            integrationsLink={{
                heading: 'Integrations',
                path: '/integrations',
            }}
            language="en"
        />,
    );

    return screen.getByRole('heading', { level: 3, name: 'Free' })
        .parentElement as HTMLElement;
}

describe('landing free plan card', () => {
    it('reads against the standard plans: no bank connections, no AI dashboards', () => {
        const card = within(renderWithTier('standard'));

        expect(
            card.getByText(
                'Get started at no cost. No bank connections included.',
            ),
        ).toBeInTheDocument();
        expect(card.getByText('Connect bank accounts')).toBeInTheDocument();
        expect(
            card.getByText('Custom dashboards with AI-built widgets')
                .parentElement,
        ).toHaveClass('opacity-40');
    });

    it('reads against the latam plans, which have no bank connections either', () => {
        const card = within(renderWithTier('latam'));

        expect(card.getByText('Get started at no cost.')).toBeInTheDocument();
        expect(
            card.queryByText(
                'Get started at no cost. No bank connections included.',
            ),
        ).not.toBeInTheDocument();
        expect(
            card.queryByText('Connect bank accounts'),
        ).not.toBeInTheDocument();
        expect(
            card.getByText('Custom dashboards with AI-built widgets')
                .parentElement,
        ).toHaveClass('opacity-40');
    });
});

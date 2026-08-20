import { PricingConfig } from '@/types/pricing';
import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Paywall from './paywall';

const mocks = vi.hoisted(() => ({
    visit: vi.fn(),
    props: {
        canUseFreePlan: false,
        canManageConnectionsForFreePlan: false,
        stats: {
            accountsCount: 3,
            transactionsCount: 1284,
            categoriesCount: 12,
        },
    },
}));

const pricing: PricingConfig = {
    plans: {
        monthly: {
            name: 'Standard Monthly',
            price: 3.99,
            original_price: null,
            stripe_lookup_key: 'monthly',
            billing_period: 'month',
            trial_days: 7,
            features: [],
        },
        yearly: {
            name: 'Standard Yearly',
            price: 23.88,
            original_price: 47.88,
            stripe_lookup_key: 'yearly',
            billing_period: 'year',
            trial_days: 15,
            features: [],
        },
    },
    defaultPlan: 'yearly',
    bestValuePlan: 'yearly',
    promo: { enabled: false, code: '', description: '', badge: '' },
    currency: 'EUR',
};

// The paywall shell renders the app logo, which reads the privacy-mode
// context the real app provides app-wide from app.tsx.
vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
    }),
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: mocks.visit },
    usePage: () => ({
        props: {
            auth: { user: { id: '1', name: 'Ada', email: 'ada@example.test' } },
            locale: 'en',
            pricing,
            ...mocks.props,
        },
    }),
}));

describe('Paywall', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.props.canUseFreePlan = false;
        mocks.props.canManageConnectionsForFreePlan = false;
        mocks.props.stats = {
            accountsCount: 3,
            transactionsCount: 1284,
            categoriesCount: 12,
        };
    });

    it('starts on the configured default plan and carries it into checkout', () => {
        render(<Paywall />);

        expect(screen.getByRole('link', { name: /Continue/ })).toHaveAttribute(
            'href',
            expect.stringContaining('plan=yearly'),
        );
    });

    it('carries the plan the user picks into checkout', () => {
        render(<Paywall />);

        fireEvent.click(screen.getByText('Monthly'));

        expect(screen.getByRole('link', { name: /Continue/ })).toHaveAttribute(
            'href',
            expect.stringContaining('plan=monthly'),
        );
    });

    it('renders the price the server hands over, per experiment arm', () => {
        render(<Paywall />);

        // Whatever the arm, the page states the monthly equivalent and the real
        // yearly charge rather than a price of its own.
        expect(
            screen.getByText('€1.99/month, billed as €23.88 a year'),
        ).toBeInTheDocument();
        expect(screen.getByText('€3.99 a month')).toBeInTheDocument();
    });

    it('names what the user already has when the gate is hard', () => {
        render(<Paywall />);

        expect(
            screen.getByText(/Your 3 accounts and 1,284 transactions/),
        ).toBeInTheDocument();
    });

    it('drops the snapshot sentence rather than saying zero', () => {
        mocks.props.stats = {
            accountsCount: 1,
            transactionsCount: 0,
            categoriesCount: 0,
        };

        render(<Paywall />);

        expect(screen.queryByText(/are already here/)).not.toBeInTheDocument();
    });

    it('offers support and no free door on a hard gate', () => {
        render(<Paywall />);

        expect(
            screen.getByRole('button', { name: /Need help\?/ }),
        ).toBeInTheDocument();
        expect(
            screen.queryByText('Continue with the free plan'),
        ).not.toBeInTheDocument();
    });

    it('offers the free door immediately on a soft gate, with no timer', () => {
        mocks.props.canUseFreePlan = true;

        render(<Paywall />);

        const free = screen.getByRole('button', {
            name: 'Continue with the free plan',
        });
        expect(free).toBeInTheDocument();

        fireEvent.click(free);
        expect(mocks.visit).toHaveBeenCalledWith('/dashboard');
    });

    it('offers the disconnect route to a former subscriber', () => {
        mocks.props.canManageConnectionsForFreePlan = true;

        render(<Paywall />);

        expect(screen.getByText('Your plan has ended')).toBeInTheDocument();
        expect(screen.getByText('Disconnect your banks')).toBeInTheDocument();
        // A former subscriber is not sold the product again.
        expect(screen.queryByText('No limits')).not.toBeInTheDocument();
    });
});

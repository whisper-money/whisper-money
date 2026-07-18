import { PricingConfig } from '@/types/pricing';
import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Billing from './billing';

const mocks = vi.hoisted(() => ({
    axiosPost: vi.fn(() => Promise.resolve({ data: {} })),
    axiosDelete: vi.fn(() => Promise.resolve({ data: {} })),
    state: { hasProPlan: false, hasAiConsent: false },
}));

vi.mock('axios', () => ({
    default: {
        post: mocks.axiosPost,
        delete: mocks.axiosDelete,
        get: vi.fn(),
        isAxiosError: () => false,
    },
}));

const pricing: PricingConfig = {
    plans: {
        monthly: {
            name: 'Monthly',
            price: 3.99,
            original_price: null,
            stripe_lookup_key: 'monthly',
            billing_period: 'month',
            features: [],
        },
        yearly: {
            name: 'Annual',
            price: 23.88,
            original_price: 47.88,
            stripe_lookup_key: 'yearly',
            billing_period: 'year',
            features: [],
        },
    },
    defaultPlan: 'yearly',
    bestValuePlan: 'yearly',
    promo: { enabled: false, code: '', description: '', badge: '' },
    currency: 'EUR',
};

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { post: vi.fn(), visit: vi.fn() },
    usePage: () => ({
        props: {
            auth: {
                isDemoAccount: false,
                hasProPlan: mocks.state.hasProPlan,
            },
            pricing,
            locale: 'en',
            hasAiConsent: mocks.state.hasAiConsent,
        },
    }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('@/layouts/settings/layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

function aiCheckbox(): HTMLElement {
    return screen.getByRole('checkbox', { name: /Allow AI categorization/i });
}

describe('Billing – AI categorization toggle', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mocks.state.hasProPlan = false;
        mocks.state.hasAiConsent = false;
    });

    it('prompts a free user to subscribe instead of recording consent', async () => {
        render(<Billing />);

        // Capture the element up front: once the dialog opens, Radix marks the
        // background as aria-hidden, so getByRole can no longer see it.
        const checkbox = aiCheckbox();
        fireEvent.click(checkbox);

        expect(
            await screen.findByText('AI categorization is a paid feature'),
        ).toBeInTheDocument();
        // The consent must NOT be recorded — that is what would lock the free
        // user behind the paywall on the next navigation.
        expect(mocks.axiosPost).not.toHaveBeenCalled();
        expect(checkbox).not.toBeChecked();
    });

    it('records consent directly for a subscribed user', async () => {
        mocks.state.hasProPlan = true;
        render(<Billing />);

        fireEvent.click(aiCheckbox());

        await waitFor(() => expect(mocks.axiosPost).toHaveBeenCalledTimes(1));
        expect(
            screen.queryByText('AI categorization is a paid feature'),
        ).not.toBeInTheDocument();
    });

    it('lets a free user with existing consent revoke it (escape the lock)', async () => {
        mocks.state.hasAiConsent = true;
        render(<Billing />);

        // Unchecking is not gated — it must revoke so a previously locked free
        // user can get out from behind the paywall.
        fireEvent.click(aiCheckbox());

        await waitFor(() => expect(mocks.axiosDelete).toHaveBeenCalledTimes(1));
        expect(mocks.axiosPost).not.toHaveBeenCalled();
        expect(
            screen.queryByText('AI categorization is a paid feature'),
        ).not.toBeInTheDocument();
    });
});

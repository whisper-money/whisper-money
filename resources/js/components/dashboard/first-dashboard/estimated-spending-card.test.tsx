import { type DashboardEstimates } from '@/types/dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { EstimatedSpendingCard } from './estimated-spending-card';

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en-US' }));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({ isPrivacyModeEnabled: false }),
    usePrivacyReveal: () => null,
}));

vi.mock('@/components/privacy-reveal-button', () => ({
    PrivacyRevealButton: () => null,
}));

const estimates: DashboardEstimates = {
    month: '2026-10',
    count: 4,
    total: 169500,
    currency_code: 'EUR',
    account_id: 'account-1',
    rows: [
        { name: 'Rent', amount: 110000 },
        { name: 'Groceries', amount: 40000 },
        { name: 'Eating out', amount: 15000 },
        { name: 'Subscriptions', amount: 4500 },
    ],
};

function renderCard(spendingGuess: number | null, guessCurrencyCode = 'EUR') {
    return render(
        <EstimatedSpendingCard
            estimates={estimates}
            spendingGuess={spendingGuess}
            guessCurrencyCode={guessCurrencyCode}
        />,
    );
}

describe('EstimatedSpendingCard', () => {
    it('labels the month as an estimate and lists what was typed, dashed', () => {
        renderCard(null);

        const card = screen.getByRole('region', { name: 'October spending' });

        expect(card.textContent).toContain('Estimate');
        expect(card.textContent).toContain('€1,695');
        expect(card.textContent).toContain('Rent');
        expect(card.textContent).toContain('€1,100');
        expect(screen.getAllByTestId('estimate-bar')).toHaveLength(4);
        expect(card.textContent).toContain(
            "Dashed bars are what you typed, not movements. They're replaced when you import October.",
        );
    });

    it('sizes each bar by its share of the month', () => {
        renderCard(null);

        const [rent] = screen.getAllByTestId('estimate-bar');

        expect((rent.firstElementChild as HTMLElement).style.width).toBe(
            `${(110000 / 169500) * 100}%`,
        );
    });

    it('says how far over the guess the estimates already are', () => {
        renderCard(120000);

        expect(
            screen.getByText('you guessed €1,200 — already €495 over'),
        ).toBeTruthy();
    });

    it('only names the guess when the estimates stay under it', () => {
        renderCard(200000);

        expect(screen.getByText('you guessed €2,000')).toBeTruthy();
    });

    it('compares nothing without a guess, or with one in another currency', () => {
        const { unmount } = renderCard(null);
        expect(screen.queryByText(/you guessed/)).toBeNull();
        unmount();

        renderCard(120000, 'USD');
        expect(screen.queryByText(/you guessed/)).toBeNull();
    });
});

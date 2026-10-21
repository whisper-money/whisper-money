import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    AmountComparison,
    PercentageComparison,
    shareOf,
} from './summary-card-parts';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('lucide-react', () => ({
    TrendingDown: ({ className }: { className: string }) => (
        <svg data-testid="trend-down" className={className} />
    ),
    TrendingUp: ({ className }: { className: string }) => (
        <svg data-testid="trend-up" className={className} />
    ),
}));

describe('shareOf', () => {
    it('reads a part as a percentage of the whole', () => {
        expect(shareOf(25, 200)).toBe(12.5);
    });

    it('is 0 when there is no whole to read against', () => {
        expect(shareOf(25, 0)).toBe(0);
        expect(shareOf(25, -10)).toBe(0);
    });
});

describe('PercentageComparison', () => {
    it('signs an increase and points it up', () => {
        render(<PercentageComparison diff={4.25} />);

        expect(screen.getByText('+4.3%')).toBeTruthy();
        expect(screen.getByTestId('trend-up')).toBeTruthy();
    });

    it('points a decrease down', () => {
        render(<PercentageComparison diff={-0.7} />);

        expect(screen.getByText('-0.7%')).toBeTruthy();
        expect(screen.getByTestId('trend-down')).toBeTruthy();
    });
});

describe('AmountComparison', () => {
    it('colours an increase as good news by default', () => {
        render(<AmountComparison diff={3600} currency="EUR" />);

        expect(screen.getByText('3600')).toBeTruthy();
        expect(screen.getByTestId('trend-up').getAttribute('class')).toContain(
            'text-green-600',
        );
    });

    it('lets the caller say an increase is bad news', () => {
        render(
            <AmountComparison diff={3600} currency="EUR" isFavorable={false} />,
        );

        expect(screen.getByTestId('trend-up').getAttribute('class')).toContain(
            'text-red-600',
        );
    });
});

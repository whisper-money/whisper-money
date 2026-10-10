import { render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    MonthlySavingsBar,
    MonthlySavingsBarChart,
} from './monthly-savings-bar-chart';

vi.mock('@/components/ui/amount-display', () => ({
    AmountDisplay: ({ amountInCents }: { amountInCents: number }) => (
        <span>{amountInCents}</span>
    ),
}));

vi.mock('@/hooks/use-locale', () => ({ useLocale: () => 'en' }));

function bars(count: number): MonthlySavingsBar[] {
    return Array.from({ length: count }, (_, index) => ({
        month: `2026-${String(index + 1).padStart(2, '0')}`,
        saved: 30000,
        target: 30000,
        status: 'met' as const,
    }));
}

/** A ResizeObserver that reports the given width once observing starts. */
function observeWidth(width: number) {
    vi.stubGlobal(
        'ResizeObserver',
        class {
            constructor(
                private callback: (
                    entries: { contentRect: { width: number } }[],
                ) => void,
            ) {}
            observe() {
                this.callback([{ contentRect: { width } }]);
            }
            disconnect() {}
        },
    );
}

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('MonthlySavingsBarChart', () => {
    it('draws every month when it cannot measure the card', () => {
        render(<MonthlySavingsBarChart bars={bars(12)} currencyCode="EUR" />);

        expect(screen.getAllByRole('img')).toHaveLength(12);
    });

    it('shows only the latest months that fit whole on a narrow card', () => {
        observeWidth(300);

        render(<MonthlySavingsBarChart bars={bars(12)} currencyCode="EUR" />);

        const labels = screen
            .getAllByRole('img')
            .map((bar) => bar.getAttribute('aria-label'));

        expect(labels).toHaveLength(4);
        expect(labels[3]).toMatch(/December 2026/);
    });

    it('draws a month with nothing saved as a flat baseline', () => {
        const { container } = render(
            <MonthlySavingsBarChart
                bars={[
                    {
                        month: '2026-10',
                        saved: 0,
                        target: 30000,
                        status: 'in_progress',
                    },
                ]}
                currencyCode="EUR"
            />,
        );

        expect(container.querySelector('.border-dashed')).toBeNull();
        expect(container.querySelector('.bg-border')).not.toBeNull();
    });
});

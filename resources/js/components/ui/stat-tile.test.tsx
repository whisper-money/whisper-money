import { AmountDisplay } from '@/components/ui/amount-display';
import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import {
    StatTile,
    StatTileDescription,
    StatTileHeader,
    StatTileLabel,
    StatTileSkeleton,
    StatTileTrend,
    StatTileValue,
} from './stat-tile';

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { locale: 'en-US' } }),
}));

describe('StatTile', () => {
    it('renders a plain medium tile by default', () => {
        render(
            <StatTile data-testid="tile">
                <StatTileLabel>Income</StatTileLabel>
                <StatTileValue>1,200</StatTileValue>
            </StatTile>,
        );

        const tile = screen.getByTestId('tile');
        expect(tile).toHaveAttribute('data-slot', 'stat-tile');
        expect(tile).toHaveAttribute('data-size', 'md');
        expect(tile).toHaveAttribute('data-variant', 'plain');
        expect(tile.className).not.toContain('bg-muted');
        expect(screen.getByText('Income')).toHaveAttribute(
            'data-slot',
            'stat-tile-label',
        );
        expect(screen.getByText('Income').className).toContain(
            'text-muted-foreground',
        );
    });

    it('sizes the value from the tile, so the amount inherits it', () => {
        render(
            <StatTile size="lg">
                <StatTileValue data-testid="value">42%</StatTileValue>
            </StatTile>,
        );

        const value = screen.getByTestId('value');
        expect(value.className).toContain(
            'group-data-[size=lg]/stat-tile:text-2xl',
        );
        expect(value.className).toContain('font-semibold');
        expect(value.className).toContain('tabular-nums');
        expect(value.parentElement).toHaveAttribute('data-size', 'lg');
    });

    it('puts a muted tile on its own surface', () => {
        render(
            <StatTile variant="muted" data-testid="tile">
                <StatTileValue>1</StatTileValue>
            </StatTile>,
        );

        expect(screen.getByTestId('tile').className).toContain('bg-muted/50');
    });

    it('composes a header, a trend and a description', () => {
        render(
            <StatTile size="sm">
                <StatTileHeader data-testid="header">
                    <StatTileLabel>Saved</StatTileLabel>
                    <span>23%</span>
                </StatTileHeader>
                <StatTileValue>120</StatTileValue>
                <StatTileTrend>+5 vs last period</StatTileTrend>
                <StatTileDescription>of what came in</StatTileDescription>
            </StatTile>,
        );

        expect(screen.getByTestId('header')).toHaveAttribute(
            'data-slot',
            'stat-tile-header',
        );
        expect(screen.getByText('+5 vs last period')).toHaveAttribute(
            'data-slot',
            'stat-tile-trend',
        );
        expect(screen.getByText('of what came in')).toHaveAttribute(
            'data-slot',
            'stat-tile-description',
        );
    });

    it('renders as a term and its definition inside a <dl>', () => {
        render(
            <dl>
                <StatTile>
                    <StatTileLabel asChild>
                        <dt>Limit</dt>
                    </StatTileLabel>
                    <StatTileValue asChild>
                        <dd>5,000</dd>
                    </StatTileValue>
                </StatTile>
            </dl>,
        );

        expect(screen.getByText('Limit').tagName).toBe('DT');
        expect(screen.getByText('Limit')).toHaveAttribute(
            'data-slot',
            'stat-tile-label',
        );
        expect(screen.getByText('5,000').tagName).toBe('DD');
    });

    it('masks an amount in privacy mode', () => {
        localStorage.setItem('privacy-mode-enabled', 'true');

        render(
            <PrivacyModeProvider>
                <StatTile>
                    <StatTileValue data-testid="value">
                        <AmountDisplay
                            amountInCents={123456}
                            currencyCode="EUR"
                        />
                    </StatTileValue>
                </StatTile>
            </PrivacyModeProvider>,
        );

        expect(screen.getByTestId('value').textContent).not.toMatch(/\d/);
        localStorage.removeItem('privacy-mode-enabled');
    });

    it('shows a skeleton the shape of the tile while it loads', () => {
        const { container } = render(
            <StatTileSkeleton size="lg" withTrend data-testid="skeleton" />,
        );

        const skeleton = screen.getByTestId('skeleton');
        expect(skeleton).toHaveAttribute('data-size', 'lg');
        expect(skeleton).toHaveAttribute('aria-hidden', 'true');
        expect(
            container.querySelectorAll('[data-slot="skeleton"]'),
        ).toHaveLength(3);
    });

    it('leaves the trend line out of a skeleton by default', () => {
        const { container } = render(<StatTileSkeleton size="sm" />);

        expect(
            container.querySelectorAll('[data-slot="skeleton"]'),
        ).toHaveLength(2);
    });
});

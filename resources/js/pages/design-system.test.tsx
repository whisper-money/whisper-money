import { fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import DesignSystem from './design-system';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    usePage: () => ({ props: { locale: 'en-US', translations: {} } }),
}));

// jsdom ships no `matchMedia`; the desktop popover is enough here.
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => false }));

// Recharts measures its container, which jsdom cannot: the chart previews are
// covered by the components' own tests.
vi.mock('@/components/ui/stacked-bar-chart', () => ({
    StackedBarChart: () => <div data-testid="stacked-bar-chart" />,
}));

vi.mock('@/components/ui/stacked-area-chart', () => ({
    StackedAreaChart: () => <div data-testid="stacked-area-chart" />,
}));

const root = document.documentElement;

afterEach(() => {
    root.classList.remove('dark');
    root.removeAttribute('data-chart-color');
    window.localStorage.clear();
    document.cookie = 'chart-color-scheme=; max-age=0';
});

describe('design system page', () => {
    it('renders every section with its side index entry', () => {
        render(<DesignSystem />);

        for (const title of [
            'Foundations',
            'Primitives',
            'Finance patterns',
            'Page patterns',
        ]) {
            expect(
                screen.getByRole('heading', { level: 2, name: title }),
            ).toBeInTheDocument();
            expect(
                within(
                    screen.getByRole('navigation', {
                        name: 'Design system sections',
                    }),
                ).getByRole('link', { name: title }),
            ).toHaveAttribute(
                'href',
                `#${title.toLowerCase().replace(' ', '-')}`,
            );
        }
    });

    it('masks the sample amounts while privacy mode is on', () => {
        render(<DesignSystem />);

        expect(screen.getByText('€12,345.67')).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: /privacy mode/i }));

        expect(screen.queryByText('€12,345.67')).not.toBeInTheDocument();
        expect(screen.getByText('€**,***.**')).toBeInTheDocument();
    });

    it('applies the theme and chart palette locally and puts them back on leave', () => {
        root.setAttribute('data-chart-color', 'blue');
        const { unmount } = render(<DesignSystem />);

        fireEvent.click(screen.getByRole('button', { name: 'Light' }));
        fireEvent.click(screen.getByRole('radio', { name: 'pink' }));

        expect(root.classList.contains('dark')).toBe(true);
        expect(root.getAttribute('data-chart-color')).toBe('pink');

        fireEvent.click(screen.getByRole('radio', { name: 'neutral' }));
        expect(root.hasAttribute('data-chart-color')).toBe(false);

        unmount();

        expect(root.classList.contains('dark')).toBe(false);
        expect(root.getAttribute('data-chart-color')).toBe('blue');
    });

    it('never persists the toggles', () => {
        render(<DesignSystem />);

        fireEvent.click(screen.getByRole('button', { name: 'Light' }));
        fireEvent.click(screen.getByRole('button', { name: /privacy mode/i }));
        fireEvent.click(screen.getByRole('radio', { name: 'pink' }));

        expect(window.localStorage.getItem('appearance')).toBeNull();
        expect(window.localStorage.getItem('chart-color-scheme')).toBeNull();
        expect(window.localStorage.getItem('privacy-mode-enabled')).toBeNull();
        expect(document.cookie).not.toContain('chart-color-scheme');
        expect(document.cookie).not.toContain('appearance');
    });
});

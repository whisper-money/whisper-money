import { type SelectedDashboard } from '@/types/dashboard';
import { render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { LockedDashboard } from './locked-dashboard';

const mocks = vi.hoisted(() => ({ captureEvent: vi.fn() }));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));

vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const DASHBOARD: SelectedDashboard = {
    id: 'dashboard-1',
    name: 'Mine',
    widgets: [
        {
            id: 'placement-2',
            system_widget: null,
            widget_definition_id: 'def-1',
            position: 1,
            width: '1/1',
            height: 'l',
            config: null,
        },
        {
            id: 'placement-1',
            system_widget: 'cashflow',
            widget_definition_id: null,
            position: 0,
            width: '1/3',
            height: 'm',
            config: null,
        },
    ],
};

describe('LockedDashboard', () => {
    it('draws a blurred placeholder at each widget size, in order, under the notice', () => {
        const { container } = render(<LockedDashboard dashboard={DASHBOARD} />);

        const placeholders = container.querySelectorAll(
            '[data-widget-placeholder]',
        );
        expect(placeholders).toHaveLength(2);
        expect(placeholders[0].className).toContain('row-span-1');
        expect(placeholders[0].className).toContain('@4xl:col-span-4');
        expect(placeholders[1].className).toContain('row-span-2');
        expect(placeholders[1].parentElement?.className).toContain('blur');
        expect(placeholders[1].parentElement?.getAttribute('aria-hidden')).toBe(
            'true',
        );

        expect(screen.getByTestId('locked-dashboard-notice')).toBeTruthy();
        expect(
            screen
                .getByRole('link', { name: 'See plans' })
                .getAttribute('href'),
        ).toBe('/subscribe');
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'custom_dashboard_locked_viewed',
            { widgets: 2 },
        );
    });
});

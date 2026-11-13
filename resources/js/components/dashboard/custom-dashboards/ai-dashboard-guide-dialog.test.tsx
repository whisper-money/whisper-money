import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AiDashboardGuideDialog } from './ai-dashboard-guide-dialog';

const mocks = vi.hoisted(() => ({
    captureEvent: vi.fn(),
    isMobile: false,
}));

vi.mock('@/lib/posthog', () => ({ captureEvent: mocks.captureEvent }));
vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mocks.isMobile }));
vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        onClick,
    }: {
        href: string;
        children: string;
        onClick?: () => void;
    }) => (
        <a
            href={href}
            onClick={(event) => {
                event.preventDefault();
                onClick?.();
            }}
        >
            {children}
        </a>
    ),
}));

const PROMPT =
    'Build me a dashboard for my motorbike with the total cost, what I spend on it each month and the list of transactions I have labelled Motorbike.';

function renderGuide(hasPlan: boolean) {
    render(
        <AiDashboardGuideDialog
            open
            onOpenChange={vi.fn()}
            hasPlan={hasPlan}
        />,
    );

    return screen.getByTestId('ai-dashboard-guide');
}

describe('AiDashboardGuideDialog', () => {
    beforeEach(() => {
        mocks.captureEvent.mockClear();
        mocks.isMobile = false;
    });

    it('walks through the three steps, with the docs and the AI Connector a click away', () => {
        const guide = renderGuide(true);

        const steps = within(guide).getAllByRole('listitem');
        expect(
            steps.map((step) => step.querySelector('p')?.textContent),
        ).toEqual([
            'Connect your assistant',
            'Ask for your dashboard',
            'Open it here',
        ]);
        expect(
            within(steps[0]).getByText('Settings → AI Connector').tagName,
        ).toBe('STRONG');

        const docs = within(guide).getByRole('link', {
            name: 'Read the full guide',
        });
        expect(docs.getAttribute('href')).toBe(
            '/documentation/custom-dashboards',
        );
        expect(docs.getAttribute('target')).toBe('_blank');
        expect(
            within(guide)
                .getByRole('link', { name: 'Open AI Connector' })
                .getAttribute('href'),
        ).toBe('/settings/mcp');
        // The dialog's corner X reads "Close" too; the footer adds the second.
        expect(
            within(guide).getAllByRole('button', { name: 'Close' }),
        ).toHaveLength(2);

        expect(
            within(guide).queryByTestId('ai-dashboard-guide-plan-banner'),
        ).toBeNull();
        expect(
            within(guide).queryByRole('link', { name: 'See plans' }),
        ).toBeNull();

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'custom_dashboard_ai_guide_opened',
            { has_plan: true },
        );
        expect(mocks.captureEvent).not.toHaveBeenCalledWith(
            'custom_dashboard_upgrade_prompted',
            expect.anything(),
        );
    });

    it('sells the Standard plan above the same steps without it', () => {
        const guide = renderGuide(false);

        expect(
            within(guide).getByTestId('ai-dashboard-guide-plan-banner')
                .textContent,
        ).toContain("It's a Standard plan feature");
        expect(within(guide).getAllByRole('listitem')).toHaveLength(3);

        const seePlans = within(guide).getByRole('link', { name: 'See plans' });
        expect(seePlans.getAttribute('href')).toBe('/subscribe');
        expect(
            within(guide).getByRole('link', { name: 'Open AI Connector' }),
        ).toBeTruthy();
        expect(
            within(guide).getAllByRole('button', { name: 'Close' }),
        ).toHaveLength(1);

        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'custom_dashboard_upgrade_prompted',
            { trigger: 'ai_dashboard_guide' },
        );

        fireEvent.click(seePlans);
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'custom_dashboard_upgrade_clicked',
            { trigger: 'ai_dashboard_guide' },
        );
    });

    it('copies the example prompt and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        const guide = renderGuide(true);
        expect(
            within(guide).getByTestId('ai-dashboard-guide-prompt').textContent,
        ).toBe(PROMPT);

        await act(async () => {
            fireEvent.click(
                within(guide).getByRole('button', { name: 'Copy' }),
            );
        });

        expect(writeText).toHaveBeenCalledWith(PROMPT);
        expect(
            within(guide).getByRole('button', { name: 'Copied' }),
        ).toBeTruthy();
        expect(mocks.captureEvent).toHaveBeenCalledWith(
            'custom_dashboard_ai_guide_prompt_copied',
        );
    });

    it('opens as a drawer on a phone, with the plan button first', () => {
        mocks.isMobile = true;

        const guide = renderGuide(false);

        expect(guide.getAttribute('data-vaul-drawer-direction')).toBe('bottom');
        const links = within(guide)
            .getAllByRole('link')
            .map((link) => link.textContent);
        expect(links).toEqual([
            'See plans',
            'Open AI Connector',
            'Read the full guide',
        ]);
    });
});

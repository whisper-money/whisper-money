import { act, fireEvent, render, screen, within } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { AiDashboardGuideDialog } from './ai-dashboard-guide-dialog';

const mocks = vi.hoisted(() => ({
    isMobile: false,
}));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => mocks.isMobile }));
vi.mock('@inertiajs/react', () => ({
    Link: ({ href, children }: { href: string; children: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const PROMPT =
    'Build me a dashboard for my motorbike with the total cost, what I spend on it each month and the list of transactions I have labelled Motorbike.';

function renderGuide() {
    render(<AiDashboardGuideDialog open onOpenChange={vi.fn()} />);

    return screen.getByTestId('ai-dashboard-guide');
}

describe('AiDashboardGuideDialog', () => {
    beforeEach(() => {
        mocks.isMobile = false;
    });

    it('walks through the three steps, with the AI Connector a click away', () => {
        const guide = renderGuide();

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
        // The apps the AI Connector page gives steps for, and no other.
        expect(steps[0].textContent).toContain('Claude or ChatGPT');
        expect(steps[0].textContent).not.toContain('Perplexity');

        expect(
            within(guide)
                .getByRole('link', { name: 'Open AI Connector' })
                .getAttribute('href'),
        ).toBe('/settings/mcp');
        // The dialog's corner X reads "Close" too; the footer adds the second.
        expect(
            within(guide).getAllByRole('button', { name: 'Close' }),
        ).toHaveLength(2);
        // No plan to sell and no docs site here.
        expect(guide.textContent).not.toMatch(/plan/i);
        expect(within(guide).getAllByRole('link')).toHaveLength(1);
    });

    it('copies the example prompt and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        Object.assign(navigator, { clipboard: { writeText } });

        const guide = renderGuide();
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
    });

    it('opens as a drawer on a phone, with the AI Connector at hand', () => {
        mocks.isMobile = true;

        const guide = renderGuide();

        expect(guide.getAttribute('data-vaul-drawer-direction')).toBe('bottom');
        expect(
            within(guide)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual(['Open AI Connector']);
    });
});

// @vitest-environment node
//
// Node, not jsdom, on purpose: this page is server-rendered, and the bug this
// guards against - reading the locale off `document.documentElement` during
// render - only shows up where there is no `document`. Under jsdom it passes
// either way while production SSR keeps failing.
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';
import MonthlySummariesIndex from './index';

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: { children: React.ReactNode }) => <a>{children}</a>,
    usePage: () => ({ props: { locale: 'en' } }),
}));

vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <>{children}</>,
}));

const summary = {
    id: '01a05c04-4125-722f-a229-273a346f35b2',
    period: '2026-01',
    card: 'streak',
    complete: true,
    sent_at: '2026-02-03T09:00:00Z',
    shared: false,
    payload: { cashflow: { savings_rate: 21 } },
};

describe('MonthlySummariesIndex', () => {
    it('renders without a DOM, as the SSR server does', () => {
        const html = renderToStaticMarkup(
            <MonthlySummariesIndex summaries={[summary]} />,
        );

        expect(html).toContain('January 2026');
        expect(html).toContain('21% saved');
    });

    it('renders the empty state without a DOM', () => {
        const html = renderToStaticMarkup(
            <MonthlySummariesIndex summaries={[]} />,
        );

        expect(html).toContain('Nothing here yet');
    });
});

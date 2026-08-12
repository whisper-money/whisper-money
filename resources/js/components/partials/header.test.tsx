import { dashboard, home } from '@/routes';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Header from './header';

const mocks = vi.hoisted(() => ({
    url: '/roadmap',
    pageProps: {
        auth: {
            user: {
                id: 'user-1',
                name: 'Test User',
                email: 'test@example.com',
            },
        },
    },
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
    }: {
        children: React.ReactNode;
        href: string | { url: string };
    }) => <a href={typeof href === 'string' ? href : href.url}>{children}</a>,
    usePage: () => ({ url: mocks.url, props: mocks.pageProps }),
}));

describe('Header', () => {
    beforeEach(() => {
        mocks.url = '/roadmap';
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
    });

    it('shows dashboard links for authenticated users', () => {
        render(<Header />);

        const dashboardLinks = screen.getAllByRole('link', {
            name: 'Dashboard',
        });

        expect(dashboardLinks).toHaveLength(2);
        expect(dashboardLinks[0]?.getAttribute('href')).toBe(dashboard().url);
    });

    it('links the logo home on every page but the landing one', () => {
        render(<Header />);

        const logoLinks = screen.getAllByRole('link', {
            name: 'Whisper Money',
        });

        expect(logoLinks).toHaveLength(2);
        expect(logoLinks[0]?.getAttribute('href')).toBe(home().url);
    });

    it('does not link the logo on the landing page', () => {
        mocks.url = '/?ref=newsletter';

        render(<Header />);

        expect(screen.getAllByText('Whisper Money')).toHaveLength(2);
        expect(
            screen.queryByRole('link', { name: 'Whisper Money' }),
        ).toBeNull();
    });
});

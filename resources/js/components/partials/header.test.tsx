import { dashboard } from '@/routes';
import { render, screen } from '@testing-library/react';
import type React from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import Header from './header';

const mocks = vi.hoisted(() => ({
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
    usePage: () => ({ props: mocks.pageProps }),
}));

describe('Header', () => {
    beforeEach(() => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() => new Promise(() => {})),
        );
    });

    it('shows dashboard links for authenticated users when auth buttons are hidden', () => {
        render(<Header hideAuthButtons />);

        const dashboardLinks = screen.getAllByRole('link', {
            name: 'Dashboard',
        });

        expect(dashboardLinks).toHaveLength(2);
        expect(dashboardLinks[0]?.getAttribute('href')).toBe(dashboard().url);
    });
});

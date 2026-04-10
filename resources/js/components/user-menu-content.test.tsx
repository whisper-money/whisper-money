import {
    DropdownMenu,
    DropdownMenuContent,
} from '@/components/ui/dropdown-menu';
import { PrivacyModeProvider } from '@/contexts/privacy-mode-context';
import { render, screen } from '@testing-library/react';
import { type ComponentProps, forwardRef } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { UserMenuContent } from './user-menu-content';

vi.mock('@/components/user-info', () => ({
    UserInfo: ({ user }: { user: { name: string; email: string } }) => (
        <div>
            <span>{user.name}</span>
            <span>{user.email}</span>
        </div>
    ),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        Link: forwardRef<
            HTMLAnchorElement,
            ComponentProps<'a'> & {
                href: string | { url: string };
                prefetch?: boolean;
                method?: string;
                as?: string;
                data?: unknown;
            }
        >(({ href, children, ...props }, ref) => {
            delete props.prefetch;
            delete props.as;
            delete props.data;
            delete props.method;

            return (
                <a
                    ref={ref}
                    href={typeof href === 'string' ? href : href.url}
                    {...props}
                >
                    {children}
                </a>
            );
        }),
        router: {
            flushAll: vi.fn(),
        },
        usePage: () => ({
            props: {
                version: '1.0.0',
            },
        }),
    };
});

const user = {
    id: 'user-1',
    name: 'Victor Falcon',
    email: 'victor@example.com',
    locale: 'en',
    currency_code: 'USD',
    email_verified_at: '2026-01-01T00:00:00Z',
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
};

beforeEach(() => {
    vi.stubGlobal('localStorage', {
        getItem: vi.fn(() => null),
        setItem: vi.fn(),
    });
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('UserMenuContent', () => {
    it('renders the appearance link between privacy mode and settings', () => {
        render(
            <PrivacyModeProvider>
                <DropdownMenu open={true}>
                    <DropdownMenuContent forceMount={true}>
                        <UserMenuContent user={user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </PrivacyModeProvider>,
        );

        const privacyModeItem = screen.getByRole('menuitem', {
            name: 'Enable privacy mode',
        });
        const appearanceItem = screen.getByRole('menuitem', {
            name: 'Appearance',
        });
        const settingsItem = screen.getByRole('menuitem', {
            name: 'Settings',
        });

        expect(appearanceItem).toHaveAttribute('href', '/settings/appearance');

        const itemLabels = screen
            .getAllByRole('menuitem')
            .map((item) => item.textContent?.trim());

        expect(
            itemLabels.indexOf(privacyModeItem.textContent?.trim() ?? ''),
        ).toBeLessThan(
            itemLabels.indexOf(appearanceItem.textContent?.trim() ?? ''),
        );
        expect(
            itemLabels.indexOf(appearanceItem.textContent?.trim() ?? ''),
        ).toBeLessThan(
            itemLabels.indexOf(settingsItem.textContent?.trim() ?? ''),
        );
    });
});

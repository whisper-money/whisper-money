import { type User } from '@/types';
import { fireEvent, render, screen } from '@testing-library/react';
import { type ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { UserMenuContent } from './user-menu-content';

vi.mock('@/components/ui/dropdown-menu', () => ({
    DropdownMenuGroup: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuItem: ({
        children,
        onClick,
    }: {
        children: ReactNode;
        onClick?: () => void;
    }) => <div onClick={onClick}>{children}</div>,
    DropdownMenuLabel: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    DropdownMenuSeparator: () => <hr />,
}));

vi.mock('@/components/user-info', () => ({
    UserInfo: () => <div>User</div>,
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode: vi.fn(),
    }),
}));

vi.mock('@/hooks/use-mobile-navigation', () => ({
    useMobileNavigation: () => vi.fn(),
}));

vi.mock('@/lib/key-storage', () => ({
    clearKey: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
        as?: string;
        prefetch?: boolean;
    }) => {
        delete props.as;
        delete props.prefetch;

        return (
            <a href={href} {...props}>
                {children}
            </a>
        );
    },
    router: {
        flushAll: vi.fn(),
    },
    usePage: () => ({
        props: {
            version: 'test-version',
        },
    }),
}));

const user: User = {
    id: '0194d20b-2b25-7000-8000-000000000001',
    name: 'Test User',
    email: 'test@example.com',
    currency_code: 'USD',
    locale: 'en',
    timezone: 'UTC',
    email_verified_at: null,
    created_at: '2026-01-01T00:00:00.000000Z',
    updated_at: '2026-01-01T00:00:00.000000Z',
};

describe('UserMenuContent', () => {
    it('shows community above feedback in the user dropdown', () => {
        render(
            <UserMenuContent
                user={user}
                onOpenSupport={vi.fn()}
                onOpenIntegrationRequests={vi.fn()}
            />,
        );

        const community = screen.getByRole('link', { name: /community/i });
        const feedback = screen.getByRole('link', { name: /feedback/i });

        expect(community.getAttribute('href')).toBe(
            'https://discord.gg/m8hUhx6D9D',
        );
        expect(community.compareDocumentPosition(feedback)).toBe(
            Node.DOCUMENT_POSITION_FOLLOWING,
        );
    });

    it('triggers the support callback when the support item is clicked', () => {
        const onOpenSupport = vi.fn();

        render(
            <UserMenuContent
                user={user}
                onOpenSupport={onOpenSupport}
                onOpenIntegrationRequests={vi.fn()}
            />,
        );

        fireEvent.click(screen.getByText('Support'));

        expect(onOpenSupport).toHaveBeenCalledOnce();
    });
});

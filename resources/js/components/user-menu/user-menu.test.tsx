import { NavUser } from '@/components/nav-user';
import { type User } from '@/types';
import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { type ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { page, mobile, togglePrivacyMode } = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown> },
    mobile: { value: false },
    togglePrivacyMode: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    // Inertia's Link as a plain anchor: the menu hands it either a URL or a
    // Wayfinder route (logout), and `as`/`prefetch` mean nothing to an <a>.
    Link: (props: Record<string, unknown>) => {
        const { href, as, prefetch, ...rest } = props as {
            href: string | { url: string };
            as?: string;
            prefetch?: boolean;
        };
        void as;
        void prefetch;

        return (
            <a {...rest} href={typeof href === 'string' ? href : href.url} />
        );
    },
    router: { flushAll: vi.fn(), patch: vi.fn() },
    usePage: () => page,
}));

vi.mock('@/components/ui/sidebar', () => ({
    SidebarMenu: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarMenuItem: ({ children }: { children: ReactNode }) => (
        <div>{children}</div>
    ),
    SidebarMenuButton: ({
        children,
        size,
        ...props
    }: {
        children: ReactNode;
        size?: string;
    }) => {
        void size;

        return (
            <button type="button" {...props}>
                {children}
            </button>
        );
    },
    useSidebar: () => ({ state: 'expanded' }),
}));

vi.mock('@/hooks/use-mobile', () => ({
    useIsMobile: () => mobile.value,
}));

vi.mock('@/contexts/privacy-mode-context', () => ({
    usePrivacyMode: () => ({
        isPrivacyModeEnabled: false,
        togglePrivacyMode,
    }),
}));

vi.mock('@/components/user-info', () => ({
    UserInfo: ({ user }: { user: User }) => <span>{user.name}</span>,
}));

vi.mock('@/components/support-dialog', () => ({
    SupportDialog: ({ open }: { open: boolean }) =>
        open ? <div>Support dialog</div> : null,
}));

vi.mock(
    '@/components/integration-requests/integration-requests-drawer',
    () => ({
        IntegrationRequestsDrawer: () => null,
    }),
);

const user: User = {
    id: '0194d20b-2b25-7000-8000-000000000001',
    name: 'Test User',
    email: 'test@example.com',
    currency_code: 'USD',
    locale: 'en',
    format_locale: 'en-US',
    timezone: 'UTC',
    email_verified_at: null,
    created_at: '2026-01-01T00:00:00.000000Z',
    updated_at: '2026-01-01T00:00:00.000000Z',
};

function setPage({
    forecast,
    isAdmin = false,
    hasProPlan = false,
    isSharedAccount = false,
    isDemoAccount = false,
}: {
    forecast: boolean;
    isAdmin?: boolean;
    hasProPlan?: boolean;
    isSharedAccount?: boolean;
    isDemoAccount?: boolean;
}) {
    page.props = {
        version: '1.2.3',
        subscriptionsEnabled: true,
        chartColorScheme: 'colorful',
        achievements: { unlocked: 40, total: 59 },
        features: {
            cashflow: true,
            fullImport: false,
            fullImportSettings: false,
            brokerImports: false,
            forecast,
        },
        auth: {
            user,
            hasProPlan,
            isAdmin,
            isSharedAccount,
            isDemoAccount,
        },
    };
}

function openDropdown() {
    const trigger = screen.getByRole('button', { name: /test user/i });
    fireEvent.pointerDown(trigger, { button: 0, ctrlKey: false });

    return screen.getByRole('menu');
}

function openSubmenu(name: RegExp) {
    const trigger = screen.getByRole('menuitem', { name });
    fireEvent.click(trigger);
    fireEvent.keyDown(trigger, { key: 'ArrowRight' });

    return screen.getByRole('menu', { name });
}

function menuItemLabels(menu: HTMLElement): string[] {
    return [...menu.querySelectorAll('[role^="menuitem"]')]
        .filter((item) => item.closest('[role="menu"]') === menu)
        .map((item) => item.textContent?.trim() ?? '');
}

beforeEach(() => {
    mobile.value = false;
    togglePrivacyMode.mockClear();
    window.matchMedia = vi.fn().mockReturnValue({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
    }) as unknown as typeof window.matchMedia;
});

describe('user menu with the Forecast flag off', () => {
    it('renders the menu everybody has today, unchanged', () => {
        setPage({ forecast: false });
        render(<NavUser />);

        expect(menuItemLabels(openDropdown())).toEqual([
            'Progress40 / 59',
            'Monthly summaries',
            'Enable privacy mode',
            'Appearance',
            'Settings',
            'Documentation',
            'Community',
            'Feedback',
            'Request integration',
            'Support',
            'Log out',
        ]);
    });

    it('keeps the dropdown on the phone, with no sheet', () => {
        setPage({ forecast: false });
        mobile.value = true;
        render(<NavUser />);

        openDropdown();

        expect(screen.queryByTestId('user-menu-sheet')).toBeNull();
        expect(screen.queryByText('Organize')).toBeNull();
    });
});

describe('user menu with the Forecast flag on (desktop)', () => {
    it('groups the shortcuts and folds the rest into submenus', () => {
        setPage({ forecast: true });
        render(<NavUser />);

        const menu = openDropdown();

        expect(within(menu).getByText('Organize')).toBeInTheDocument();
        expect(menuItemLabels(menu)).toEqual([
            'Progress40 / 59',
            'Categories',
            'Labels',
            'Automation rules',
            'Monthly summaries',
            'Privacy mode',
            'Settings',
            'AppearanceSystem',
            'Help & community',
            'Log out',
        ]);
        expect(
            within(menu).getByRole('menuitem', { name: /categories/i }),
        ).toHaveAttribute('href', '/settings/categories');
        expect(within(menu).getByText('Version 1.2.3')).toBeInTheDocument();
    });

    it('closes the menu once an entry is picked', () => {
        setPage({ forecast: true });
        render(<NavUser />);

        fireEvent.click(
            within(openDropdown()).getByRole('menuitem', {
                name: /categories/i,
            }),
        );

        expect(screen.queryByRole('menu')).toBeNull();
    });

    it('shows privacy mode as a switch that keeps the menu open', () => {
        setPage({ forecast: true });
        render(<NavUser />);
        openDropdown();

        const privacy = screen.getByRole('menuitemcheckbox', {
            name: /privacy mode/i,
        });
        expect(privacy).toHaveAttribute('aria-checked', 'false');

        fireEvent.click(privacy);

        expect(togglePrivacyMode).toHaveBeenCalledOnce();
        expect(screen.getByRole('menu')).toBeInTheDocument();
    });

    it('opens the settings submenu grouped by data, account and integrations', () => {
        setPage({ forecast: true, hasProPlan: true });
        render(<NavUser />);
        openDropdown();

        const settings = openSubmenu(/^settings$/i);

        for (const title of ['Data', 'Account', 'Integrations']) {
            expect(within(settings).getByText(title)).toBeInTheDocument();
        }
        expect(menuItemLabels(settings)).toEqual([
            'Bank accounts',
            'Connections',
            'Profile',
            'Notifications',
            'Plan and billingPro',
            'AI Connector (MCP)',
            'API',
        ]);
        expect(
            within(settings).getByRole('menuitem', { name: /bank accounts/i }),
        ).toHaveAttribute('href', '/settings/accounts');
    });

    it('grows a submenu out of its row and only fades it under reduced motion', () => {
        setPage({ forecast: true });
        render(<NavUser />);
        openDropdown();

        const help = openSubmenu(/^help & community$/i);

        expect(help).toHaveAttribute('data-side', 'right');
        expect(help).toHaveClass(
            'origin-(--radix-dropdown-menu-content-transform-origin)',
            'data-[state=open]:zoom-in-95',
            'data-[side=right]:slide-in-from-left-2',
            'data-[state=closed]:data-[side=right]:slide-out-to-left-2',
            'duration-150',
            'ease-out',
            'motion-reduce:[--tw-enter-translate-x:0]!',
            'motion-reduce:[--tw-enter-scale:1]!',
        );
    });

    it('offers a shared account only the settings it can open', () => {
        setPage({ forecast: true, isSharedAccount: true, isDemoAccount: true });
        render(<NavUser />);
        openDropdown();

        const settings = openSubmenu(/^settings$/i);

        expect(menuItemLabels(settings)).toEqual([
            'Bank accounts',
            'Connections',
        ]);
        expect(within(settings).queryByText('Account')).toBeNull();
    });

    it('picks the theme and the chart colors as radio items', () => {
        setPage({ forecast: true });
        render(<NavUser />);
        openDropdown();

        const appearance = openSubmenu(/appearance/i);
        const radios = within(appearance).getAllByRole('menuitemradio');

        expect(radios.map((radio) => radio.textContent)).toEqual([
            'Light',
            'Dark',
            'System',
            'Colorful',
            'Neutral',
            'Blue',
            'Pink',
        ]);

        act(() => {
            fireEvent.click(
                within(appearance).getByRole('menuitemradio', { name: 'Dark' }),
            );
        });

        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('keeps help and community in its own submenu', () => {
        setPage({ forecast: true });
        render(<NavUser />);
        openDropdown();

        const help = openSubmenu(/help & community/i);

        expect(menuItemLabels(help)).toEqual([
            'Documentation',
            'Support',
            'Community (Discord)↗',
            'Send feedback↗',
            'Request integration',
        ]);
        expect(
            within(help).getByRole('menuitem', { name: /community/i }),
        ).toHaveAttribute('href', 'https://discord.gg/m8hUhx6D9D');

        fireEvent.click(
            within(help).getByRole('menuitem', { name: 'Support' }),
        );

        expect(screen.getByText('Support dialog')).toBeInTheDocument();
    });

    it('shows the plan next to the name', () => {
        setPage({ forecast: true, hasProPlan: false });
        render(<NavUser />);
        openDropdown();

        expect(screen.getByTestId('user-menu-plan')).toHaveTextContent('Free');
    });

    it('shows Admin to the admin only', () => {
        setPage({ forecast: true });
        const { unmount } = render(<NavUser />);
        openDropdown();
        expect(screen.queryByRole('menuitem', { name: 'Admin' })).toBeNull();
        unmount();

        setPage({ forecast: true, isAdmin: true });
        render(<NavUser />);
        openDropdown();
        expect(screen.getByRole('menuitem', { name: 'Admin' })).toHaveAttribute(
            'href',
            '/admin',
        );
    });

    it('never offers Accounts on desktop', () => {
        setPage({ forecast: true });
        render(<NavUser />);

        expect(
            within(openDropdown()).queryByRole('menuitem', {
                name: 'Accounts',
            }),
        ).toBeNull();
    });
});

describe('user menu with the Forecast flag on (phone)', () => {
    function openSheet() {
        fireEvent.click(screen.getByRole('button', { name: /test user/i }));

        return screen.getByTestId('user-menu-sheet');
    }

    it('opens a bottom sheet whose Organize tiles start with Accounts', () => {
        setPage({ forecast: true, hasProPlan: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();

        expect(screen.queryByRole('menu')).toBeNull();
        const tiles = within(sheet)
            .getByText('Organize')
            .parentElement!.querySelectorAll('a');
        expect([...tiles].map((tile) => tile.textContent)).toEqual([
            'Accounts',
            'Categories',
            'Labels',
            'Rules',
        ]);
        expect(tiles[0]).toHaveAttribute('href', '/accounts');
        expect(
            within(sheet).getByRole('switch', { name: /privacy mode/i }),
        ).toHaveAttribute('aria-checked', 'false');
        expect(within(sheet).queryByText('Admin')).toBeNull();
    });

    it('drills into a level and comes back', async () => {
        setPage({ forecast: true, isAdmin: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();
        expect(within(sheet).getByText('Admin')).toBeInTheDocument();

        fireEvent.click(
            within(sheet).getByRole('button', { name: /^settings/i }),
        );

        await waitFor(() =>
            expect(document.activeElement).toBe(
                within(sheet).getByRole('heading', { name: 'Settings' }),
            ),
        );
        expect(
            within(sheet).getByRole('link', { name: /bank accounts/i }),
        ).toHaveAttribute('href', '/settings/accounts');
        await waitFor(() =>
            expect(within(sheet).queryByText('Organize')).toBeNull(),
        );

        fireEvent.click(within(sheet).getByRole('button', { name: 'Back' }));

        expect(within(sheet).getByText('Organize')).toBeInTheDocument();
        await waitFor(() =>
            expect(document.activeElement).toBe(
                within(sheet).getByRole('button', { name: /^settings/i }),
            ),
        );
    });

    function levelPanels(sheet: HTMLElement): HTMLElement[] {
        return [
            ...within(sheet).getByTestId('user-menu-sheet-levels').children,
        ] as HTMLElement[];
    }

    it('slides the new level in while the current one slides out', async () => {
        setPage({ forecast: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();
        expect(levelPanels(sheet)).toHaveLength(1);

        fireEvent.click(
            within(sheet).getByRole('button', { name: /^settings/i }),
        );

        // Both levels are on screen while they move; the one leaving can no
        // longer be focused or read, so there is a single way through.
        const [leaving, entering] = levelPanels(sheet);
        expect(leaving).toHaveAttribute('inert');
        expect(leaving).toHaveAttribute('aria-hidden', 'true');
        expect(leaving).toHaveTextContent('Organize');
        expect(entering).not.toHaveAttribute('inert');
        expect(entering.style.transform).toContain('translateX(100%)');
        expect(
            within(sheet).getAllByRole('heading', { name: 'Settings' }),
        ).toHaveLength(1);

        await waitFor(() => expect(levelPanels(sheet)).toHaveLength(1));

        fireEvent.click(within(sheet).getByRole('button', { name: 'Back' }));

        // Back reverses it: the root comes in from the left.
        expect(levelPanels(sheet)[1].style.transform).toContain(
            'translateX(-100%)',
        );
        await waitFor(() => expect(levelPanels(sheet)).toHaveLength(1));
    });

    it('settles on the root when Back is tapped mid-transition', async () => {
        setPage({ forecast: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();
        fireEvent.click(
            within(sheet).getByRole('button', { name: /^settings/i }),
        );
        fireEvent.click(within(sheet).getByRole('button', { name: 'Back' }));

        await waitFor(() => expect(levelPanels(sheet)).toHaveLength(1));
        expect(within(sheet).getByText('Organize')).toBeInTheDocument();
        await waitFor(() =>
            expect(document.activeElement).toBe(
                within(sheet).getByRole('button', { name: /^settings/i }),
            ),
        );
    });

    it('only crossfades the levels under reduced motion', async () => {
        window.matchMedia = vi.fn().mockImplementation((query: string) => ({
            matches: query === '(prefers-reduced-motion: reduce)',
            addEventListener: vi.fn(),
            removeEventListener: vi.fn(),
        })) as unknown as typeof window.matchMedia;
        setPage({ forecast: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();
        fireEvent.click(within(sheet).getByRole('button', { name: /^help/i }));

        const [, entering] = levelPanels(sheet);
        expect(entering.style.transform).not.toContain('translateX');
        expect(entering.style.opacity).toBe('0');

        await waitFor(() =>
            expect(document.activeElement).toBe(
                within(sheet).getByRole('heading', {
                    name: 'Help & community',
                }),
            ),
        );
        expect(levelPanels(sheet)).toHaveLength(1);
    });

    it('marks the current theme in the appearance level', () => {
        setPage({ forecast: true });
        mobile.value = true;
        render(<NavUser />);

        const sheet = openSheet();
        fireEvent.click(
            within(sheet).getByRole('button', { name: /^appearance/i }),
        );

        const theme = within(sheet).getByRole('radiogroup', { name: 'Theme' });
        fireEvent.click(within(theme).getByRole('radio', { name: 'Light' }));

        expect(
            within(theme).getByRole('radio', { name: 'Light' }),
        ).toHaveAttribute('aria-checked', 'true');
    });
});

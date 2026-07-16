import { index as accountsIndex } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { index as categoriesIndex } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { index as labelsIndex } from '@/actions/App/Http/Controllers/Settings/LabelController';
import { index as spacesIndex } from '@/actions/App/Http/Controllers/SpaceController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectGroup,
    SelectItem,
    SelectLabel,
    SelectSeparator,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { cn, isSameUrl, resolveUrl } from '@/lib/utils';
import { edit as editAccount } from '@/routes/account';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editDeleteAccount } from '@/routes/delete-account';
import { billing } from '@/routes/settings';
import {
    NavDivider,
    NavSectionHeader,
    SharedData,
    type NavItem,
} from '@/types';
import { __ } from '@/utils/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import { Menu } from 'lucide-react';
import { type PropsWithChildren } from 'react';

const getNavItems = (
    subscriptionsEnabled: boolean,
    isDemoAccount: boolean,
    spacesEnabled: boolean,
): (NavItem | NavSectionHeader | NavDivider)[] => [
    ...(spacesEnabled
        ? [
              {
                  type: 'nav-item' as const,
                  title: 'Spaces',
                  href: spacesIndex(),
                  icon: null,
              },
          ]
        : []),
    {
        type: 'nav-item' as const,
        title: 'Bank accounts',
        href: accountsIndex(),
        icon: null,
    },
    {
        type: 'nav-item' as const,
        title: 'Connections',
        href: '/settings/connections',
        icon: null,
    },
    {
        type: 'nav-item' as const,
        title: 'Automation rules',
        href: automationRulesIndex(),
        icon: null,
    },
    {
        type: 'nav-item' as const,
        title: 'Categories',
        href: categoriesIndex(),
        icon: null,
    },
    {
        type: 'nav-item' as const,
        title: 'Labels',
        href: labelsIndex(),
        icon: null,
    },
    { type: 'divider' },
    {
        type: 'section-header',
        title: 'Profile Settings',
    },
    ...(!isDemoAccount
        ? [
              {
                  type: 'nav-item' as const,
                  title: 'User account',
                  href: editAccount(),
                  icon: null,
              },
          ]
        : []),
    ...(subscriptionsEnabled && !isDemoAccount
        ? [
              {
                  type: 'nav-item' as const,
                  title: 'Manage Plan',
                  href: billing(),
                  icon: null,
              },
          ]
        : []),
    {
        type: 'nav-item' as const,
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    ...(!isDemoAccount
        ? [
              { type: 'divider' as const },
              {
                  type: 'nav-item' as const,
                  title: 'Delete Account',
                  href: editDeleteAccount(),
                  icon: null,
              },
          ]
        : []),
];

function renderMobileNavGroups(
    items: (NavItem | NavSectionHeader | NavDivider)[],
) {
    const elements: React.ReactNode[] = [];
    let currentGroupLabel: string | null = null;
    let currentGroupItems: NavItem[] = [];

    const flushGroup = () => {
        if (currentGroupItems.length === 0) {
            return;
        }

        const groupKey = currentGroupLabel ?? 'default';

        if (currentGroupLabel) {
            elements.push(
                <SelectGroup key={groupKey}>
                    <SelectLabel>{__(currentGroupLabel)}</SelectLabel>
                    {currentGroupItems.map((item) => (
                        <SelectItem
                            key={resolveUrl(item.href)}
                            value={resolveUrl(item.href)}
                        >
                            {__(item.title)}
                        </SelectItem>
                    ))}
                </SelectGroup>,
            );
        } else {
            elements.push(
                ...currentGroupItems.map((item) => (
                    <SelectItem
                        key={resolveUrl(item.href)}
                        value={resolveUrl(item.href)}
                    >
                        {__(item.title)}
                    </SelectItem>
                )),
            );
        }

        currentGroupItems = [];
    };

    for (const item of items) {
        if (item.type === 'divider') {
            flushGroup();
            elements.push(<SelectSeparator key={`sep-${elements.length}`} />);
            currentGroupLabel = null;
        } else if (item.type === 'section-header') {
            flushGroup();
            currentGroupLabel = item.title;
        } else {
            currentGroupItems.push(item);
        }
    }

    flushGroup();

    return elements;
}

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { subscriptionsEnabled, auth, features } =
        usePage<SharedData>().props;
    const isDemoAccount = auth?.isDemoAccount ?? false;

    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;
    const sidebarNavItems = getNavItems(
        subscriptionsEnabled,
        isDemoAccount,
        features?.spaces ?? false,
    );

    const activeNavItem = sidebarNavItems.find(
        (item): item is NavItem =>
            item.type === 'nav-item' && isSameUrl(currentPath, item.href),
    );

    return (
        <div className="px-4 py-6">
            <Heading
                title={__('Settings')}
                description={__('Manage your profile and account settings')}
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                {/* Mobile: dropdown select */}
                <div
                    className="-mt-4 sm:mt-0 lg:hidden"
                    data-testid="settings-mobile-nav"
                >
                    <Select
                        value={
                            activeNavItem
                                ? resolveUrl(activeNavItem.href)
                                : undefined
                        }
                        onValueChange={(value) => router.visit(value)}
                    >
                        <SelectTrigger
                            className="w-full"
                            data-testid="settings-mobile-nav-trigger"
                        >
                            <div className="flex w-full flex-row items-center gap-2">
                                <Menu
                                    aria-hidden="true"
                                    className="size-4 text-muted-foreground"
                                    data-testid="settings-mobile-nav-icon"
                                />
                                <SelectValue
                                    placeholder={__('Navigate to...')}
                                />
                            </div>
                        </SelectTrigger>
                        <SelectContent>
                            {renderMobileNavGroups(sidebarNavItems)}
                        </SelectContent>
                    </Select>
                </div>

                {/* Desktop: sidebar nav */}
                <aside className="hidden w-48 lg:block">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item, index) => {
                            if (item.type === 'divider') {
                                return (
                                    <Separator
                                        key={`divider-${index}`}
                                        className="my-2 ml-0 opacity-0"
                                    />
                                );
                            } else if (item.type === 'section-header') {
                                return (
                                    <h2
                                        key={`section-header-${index}`}
                                        className="px-3 pt-2 pb-1.5 text-sm font-medium text-muted-foreground"
                                    >
                                        {__(item.title)}
                                    </h2>
                                );
                            }

                            return (
                                <Button
                                    key={`${resolveUrl(item.href)}-${index}`}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn('w-full justify-start', {
                                        'bg-muted': isSameUrl(
                                            currentPath,
                                            item.href,
                                        ),
                                    })}
                                >
                                    <Link href={item.href}>
                                        {typeof item.icon === 'function' ? (
                                            <item.icon className="h-4 w-4" />
                                        ) : (
                                            item.icon
                                        )}
                                        {__(item.title)}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <div className="flex-1 md:max-w-4xl">
                    <section className="mt-6 max-w-3xl space-y-12 lg:mt-0">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

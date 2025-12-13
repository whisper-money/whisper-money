import { index as accountsIndex } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { index as categoriesIndex } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { index as labelsIndex } from '@/actions/App/Http/Controllers/Settings/LabelController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
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
import { Link, usePage } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

const getNavItems = (
    subscriptionsEnabled: boolean,
): (NavItem | NavSectionHeader | NavDivider)[] => [
    {
        type: 'nav-item',
        title: 'Bank accounts',
        href: accountsIndex(),
        icon: null,
    },
    {
        type: 'nav-item',
        title: 'Automation rules',
        href: automationRulesIndex(),
        icon: null,
    },
    {
        type: 'nav-item',
        title: 'Categories',
        href: categoriesIndex(),
        icon: null,
    },
    {
        type: 'nav-item',
        title: 'Labels',
        href: labelsIndex(),
        icon: null,
    },
    { type: 'divider' },
    {
        type: 'section-header',
        title: 'Profile Settings',
    },
    {
        type: 'nav-item',
        title: 'User account',
        href: editAccount(),
        icon: null,
    },
    ...(subscriptionsEnabled
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
        type: 'nav-item',
        title: 'Appearance',
        href: editAppearance(),
        icon: null,
    },
    { type: 'divider' },
    {
        type: 'nav-item',
        title: 'Delete Account',
        href: editDeleteAccount(),
        icon: null,
    },
];

export default function SettingsLayout({ children }: PropsWithChildren) {
    const { subscriptionsEnabled } = usePage<SharedData>().props;

    // When server-side rendering, we only render the layout on the client...
    if (typeof window === 'undefined') {
        return null;
    }

    const currentPath = window.location.pathname;
    const sidebarNavItems = getNavItems(subscriptionsEnabled);

    return (
        <div className="px-4 py-6">
            <Heading
                title="Settings"
                description="Manage your profile and account settings"
            />

            <div className="flex flex-col lg:flex-row lg:space-x-12">
                <aside className="w-full max-w-xl lg:w-48">
                    <nav className="flex flex-col space-y-1 space-x-0">
                        {sidebarNavItems.map((item, index) => {
                            if ('type' in item && item.type === 'divider') {
                                return (
                                    <Separator
                                        key={`divider-${index}`}
                                        className="my-2 ml-0 opacity-0"
                                    />
                                );
                            } else if (
                                'type' in item &&
                                item.type === 'section-header'
                            ) {
                                return (
                                    <h2
                                        key={`section-header-${index}`}
                                        className="px-3 pt-2 pb-1.5 text-sm font-medium text-muted-foreground"
                                    >
                                        {item.title}
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
                                        {item.icon && (
                                            <item.icon className="h-4 w-4" />
                                        )}
                                        {item.title}
                                    </Link>
                                </Button>
                            );
                        })}
                    </nav>
                </aside>

                <Separator className="my-6 lg:hidden" />

                <div className="flex-1 md:max-w-4xl">
                    <section className="max-w-3xl space-y-12">
                        {children}
                    </section>
                </div>
            </div>
        </div>
    );
}

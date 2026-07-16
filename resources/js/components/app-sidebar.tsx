import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { SpaceSwitcher } from '@/components/space-switcher';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { cn, resolveUrl } from '@/lib/utils';
import {
    footerNavItems,
    getMainNavItems,
} from '@/providers/menu-item-provider';
import { dashboard } from '@/routes';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import { useMemo } from 'react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const page = usePage<SharedData>();
    const mainNavItems = useMemo(
        () => getMainNavItems(page.props.features, page.props.locale),
        [page.props.features, page.props.locale],
    );
    const { trigger } = useWebHaptics();

    return (
        <>
            <div className="fixed right-4 bottom-6 left-4 z-50 flex items-center justify-evenly gap-1 rounded-full border border-border/75 bg-sidebar/50 px-2 py-2 shadow-lg shadow-black/20 backdrop-blur md:hidden">
                {mainNavItems.map((item) => {
                    const isActive = page.url.startsWith(resolveUrl(item.href));
                    const label = item.mobileTitle ?? item.title;
                    const Icon = item.icon as LucideIcon | null;
                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            onClick={() => trigger('selection')}
                            className={cn([
                                'flex flex-1 flex-col items-center justify-center gap-1 rounded-full px-3 py-2 transition-all duration-200',
                                {
                                    'bg-primary/5 dark:bg-primary/15': isActive,
                                    'opacity-50 hover:opacity-75': !isActive,
                                },
                            ])}
                        >
                            {Icon && <Icon className="size-5 text-primary" />}
                            <span className="text-[10px] leading-none font-medium text-primary">
                                {label}
                            </span>
                        </Link>
                    );
                })}
            </div>

            <Sidebar collapsible="icon" variant="inset">
                <SidebarHeader>
                    <SidebarMenu>
                        <SidebarMenuItem>
                            <SidebarMenuButton size="lg" asChild>
                                <Link href={dashboard()} prefetch>
                                    <AppLogo />
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    </SidebarMenu>
                    <SpaceSwitcher />
                </SidebarHeader>

                <SidebarContent>
                    <NavMain items={mainNavItems} />
                </SidebarContent>

                <SidebarFooter>
                    {footerNavItems.length > 0 && (
                        <NavFooter items={footerNavItems} className="mt-auto" />
                    )}
                    <NavUser />
                </SidebarFooter>
            </Sidebar>
        </>
    );
}

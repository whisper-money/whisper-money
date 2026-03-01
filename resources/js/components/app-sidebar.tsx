import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { cn, resolveUrl } from '@/lib/utils';
import {
    footerNavItems,
    getMainNavItems,
} from '@/providers/menu-item-provider';
import { dashboard } from '@/routes';
import { SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { useMemo } from 'react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const page = usePage<SharedData>();
    const mainNavItems = useMemo(
        () => getMainNavItems(page.props.features),
        [page.props.features],
    );

    return (
        <>
            <div className="fixed right-4 bottom-6 left-4 z-50 flex items-center justify-evenly gap-2 rounded-full border border-border/75 bg-sidebar/50 px-4 py-4 shadow-lg shadow-black/20 backdrop-blur md:hidden">
                {mainNavItems.map((item) => {
                    const isActive = page.url.startsWith(resolveUrl(item.href));
                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            className={cn([
                                'flex flex-col items-center justify-center text-primary',
                                'transtion-all duration-200',
                                {
                                    'opacity-100': isActive,
                                    'opacity-50': !isActive,
                                },
                                'hover:opacity-75',
                            ])}
                        >
                            <item.icon className="size-6 text-primary" />
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
                </SidebarHeader>

                <SidebarContent>
                    <NavMain items={mainNavItems} />
                </SidebarContent>

                <SidebarFooter>
                    <NavFooter items={footerNavItems} className="mt-auto" />
                    <NavUser />
                </SidebarFooter>
            </Sidebar>
        </>
    );
}

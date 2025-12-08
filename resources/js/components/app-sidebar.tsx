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
import { footerNavItems, mainNavItems } from '@/providers/menu-item-provider';
import { dashboard } from '@/routes';
import { Link, usePage } from '@inertiajs/react';
import AppLogo from './app-logo';

export function AppSidebar() {
    const page = usePage();

    return (
        <>
            <div className="fixed right-4 bottom-6 left-4 z-50 flex items-center justify-evenly gap-6 rounded-full border border-border/75 bg-sidebar/50 px-8 py-3 shadow-lg shadow-black/20 backdrop-blur md:hidden">
                {mainNavItems.map((item) => {
                    const isActive = page.url.startsWith(resolveUrl(item.href));
                    return (
                        <Link
                            key={item.title}
                            href={item.href}
                            className={cn([
                                'flex flex-col items-center justify-center gap-2 text-primary',
                                'transtion-all duration-200',
                                {
                                    'opacity-100': isActive,
                                    'opacity-50': !isActive,
                                },
                                'hover:opacity-75',
                            ])}
                        >
                            <item.icon className="size-5 text-primary" />
                            <span className="text-xs">{item.title}</span>
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

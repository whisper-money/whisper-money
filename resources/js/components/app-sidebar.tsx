import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
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
import { dashboard } from '@/routes';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/react';
import { CreditCard, Github, LayoutGrid, Receipt } from 'lucide-react';
import AppLogo from './app-logo';
import DiscordIcon from './icons/DiscordIcon';

const mainNavItems: NavItem[] = [
    {
        type: 'nav-item',
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        type: 'nav-item',
        title: 'Accounts',
        href: accountsIndex(),
        icon: CreditCard,
    },
    {
        type: 'nav-item',
        title: 'Transactions',
        href: transactionsIndex(),
        icon: Receipt,
    },
];

const footerNavItems: NavItem[] = [
    {
        type: 'nav-item',
        title: 'Github',
        href: 'https://github.com/whisper-money/whisper-money',
        icon: Github
    },
    {
        type: 'nav-item',
        title: 'Community',
        href: 'https://discord.gg/zqfrynthvb',
        icon: <DiscordIcon className="size-5" />
    }
];

export function AppSidebar() {
    return (
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
    );
}

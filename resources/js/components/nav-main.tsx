import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { resolveUrl } from '@/lib/utils';
import { type NavItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const page = usePage();
    const { trigger } = useWebHaptics();
    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>{__('Platform')}</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton
                            asChild
                            isActive={page.url.startsWith(
                                resolveUrl(item.href),
                            )}
                            tooltip={{ children: __(item.title) }}
                        >
                            <Link
                                href={item.href}
                                prefetch
                                onClick={() => trigger('selection')}
                            >
                                {item.icon && <item.icon />}
                                <span>{__(item.title)}</span>
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </SidebarGroup>
    );
}

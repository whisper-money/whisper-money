import { IntegrationRequestsDrawer } from '@/components/integration-requests/integration-requests-drawer';
import { SupportDialog } from '@/components/support-dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { UserInfo } from '@/components/user-info';
import { UserMenuContent } from '@/components/user-menu-content';
import { UserMenuDropdownContent } from '@/components/user-menu/user-menu-dropdown-content';
import { UserMenuSheet } from '@/components/user-menu/user-menu-sheet';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { ChevronsUpDown } from 'lucide-react';
import { useState } from 'react';

export function NavUser({ className }: { className?: string }) {
    const { auth, features } = usePage<SharedData>().props;
    const { state } = useSidebar();
    const isMobile = useIsMobile();
    const [supportOpen, setSupportOpen] = useState(false);
    const [integrationRequestsOpen, setIntegrationRequestsOpen] =
        useState(false);
    const [sheetOpen, setSheetOpen] = useState(false);
    // The reorganised menu ships with the forecast; with the flag off the menu
    // is exactly the one everybody has today.
    const reorganized = features?.forecast === true;
    const menuHandlers = {
        user: auth.user,
        onOpenSupport: () => setSupportOpen(true),
        onOpenIntegrationRequests: () => setIntegrationRequestsOpen(true),
    };

    const trigger = (
        <SidebarMenuButton
            size="lg"
            className="group p-0 text-sidebar-accent-foreground sm:p-2 sm:data-[state=open]:bg-sidebar-accent"
            data-test="sidebar-menu-button"
            {...(reorganized && isMobile
                ? {
                      onClick: () => setSheetOpen(true),
                      'aria-haspopup': 'dialog' as const,
                      'aria-expanded': sheetOpen,
                  }
                : {})}
        >
            <UserInfo user={auth.user} hideNameOnMobile />
            <ChevronsUpDown className="ml-auto hidden size-4 sm:block" />
        </SidebarMenuButton>
    );

    return (
        <SidebarMenu className={className}>
            <SidebarMenuItem>
                {reorganized && isMobile ? (
                    <>
                        {trigger}
                        <UserMenuSheet
                            {...menuHandlers}
                            open={sheetOpen}
                            onOpenChange={setSheetOpen}
                        />
                    </>
                ) : (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            {trigger}
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            className={cn(
                                'w-(--radix-dropdown-menu-trigger-width) min-w-56 rounded-lg',
                                reorganized && 'min-w-68',
                            )}
                            align="end"
                            side={
                                isMobile
                                    ? 'bottom'
                                    : state === 'collapsed'
                                      ? 'left'
                                      : 'bottom'
                            }
                        >
                            {reorganized ? (
                                <UserMenuDropdownContent {...menuHandlers} />
                            ) : (
                                <UserMenuContent {...menuHandlers} />
                            )}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
                <SupportDialog
                    open={supportOpen}
                    onOpenChange={setSupportOpen}
                    user={auth.user}
                />
                <IntegrationRequestsDrawer
                    open={integrationRequestsOpen}
                    onOpenChange={setIntegrationRequestsOpen}
                />
            </SidebarMenuItem>
        </SidebarMenu>
    );
}

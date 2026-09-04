import { Breadcrumbs } from '@/components/breadcrumbs';
import { EncryptionKeyButton } from '@/components/encryption-key-button';
import { NotificationBell } from '@/components/notifications/notification-bell';
import { ImportTransactionsButton } from '@/components/transactions/import-transactions-button';
import { Separator } from '@/components/ui/separator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import {
    type BreadcrumbItem as BreadcrumbItemType,
    type SharedData,
} from '@/types';
import { usePage } from '@inertiajs/react';
import { type ReactNode } from 'react';
import AppLogo from './app-logo';
import { NavUser } from './nav-user';

export function AppSidebarHeader({
    breadcrumbs = [],
    mobileLeading,
}: {
    breadcrumbs?: BreadcrumbItemType[];
    mobileLeading?: ReactNode;
}) {
    const {
        hasEncryptionSetup,
        hasEncryptedAccounts,
        hasEncryptedTransactions,
    } = usePage<SharedData>().props;

    const showEncryptionButton =
        hasEncryptionSetup &&
        (hasEncryptedTransactions || hasEncryptedAccounts);

    return (
        <header className="flex min-h-16 shrink-0 items-center border-b border-sidebar-border/50 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:min-h-12">
            {/* Padding lives inside the capped row so it lines up with the page content */}
            <div
                className="mx-auto flex w-full max-w-page items-center justify-between gap-2 px-5 sm:px-6"
                data-testid="page-header"
            >
                <div className="flex items-center gap-2 md:hidden">
                    {mobileLeading ?? <AppLogo mobile />}
                </div>
                <div className="hidden items-center gap-2 md:flex">
                    <SidebarTrigger className="-ml-1" />
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
                <div className="flex items-center gap-2">
                    <ImportTransactionsButton />
                    {showEncryptionButton && (
                        <>
                            <Separator
                                orientation="vertical"
                                className="hidden data-[orientation=vertical]:h-6 sm:block"
                            />
                            <EncryptionKeyButton />
                        </>
                    )}
                    {/* Tools on the left, the account on the right: the bell and
                        the avatar read as a pair.

                        Hidden at `md`, not at `sm`, because that is where the
                        sidebar takes over (`hidden md:block` in `ui/sidebar`).
                        Switching at `sm` left a 640-767px window with no bell
                        and no account menu anywhere on the screen. */}
                    <Separator
                        orientation="vertical"
                        className="data-[orientation=vertical]:h-6 md:hidden"
                    />
                    <NotificationBell className="md:hidden" />
                    <NavUser className="md:hidden" />
                </div>
            </div>
        </header>
    );
}

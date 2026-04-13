import { Breadcrumbs } from '@/components/breadcrumbs';
import { EncryptionKeyButton } from '@/components/encryption-key-button';
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
        <header className="flex min-h-16 shrink-0 items-center justify-between gap-2 border-b border-sidebar-border/50 px-5 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:min-h-12 sm:px-6 md:px-4">
            <div className="flex items-center gap-2 sm:hidden">
                {mobileLeading ?? <AppLogo mobile />}
            </div>
            <div className="hidden items-center gap-2 sm:flex">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex items-center gap-2">
                <ImportTransactionsButton />
                {showEncryptionButton && (
                    <>
                        <Separator
                            orientation="vertical"
                            className="data-[orientation=vertical]:h-6"
                        />
                        <EncryptionKeyButton />
                    </>
                )}
                <Separator
                    orientation="vertical"
                    className="data-[orientation=vertical]:h-6 sm:hidden"
                />
                <NavUser className="sm:hidden" />
            </div>
        </header>
    );
}

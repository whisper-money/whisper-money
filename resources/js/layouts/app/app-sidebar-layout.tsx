import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { useDecryptAccountNames } from '@/hooks/use-decrypt-account-names';
import { useDecryptTransactions } from '@/hooks/use-decrypt-transactions';
import { type BreadcrumbItem } from '@/types';
import { type PropsWithChildren, type ReactNode } from 'react';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
    mobileLeading,
}: PropsWithChildren<{
    breadcrumbs?: BreadcrumbItem[];
    mobileLeading?: ReactNode;
}>) {
    useDecryptAccountNames();
    useDecryptTransactions();

    return (
        <AppShell variant="sidebar">
            <AppSidebar />
            <AppContent
                variant="sidebar"
                className="pt-safe overflow-x-hidden pb-[90px] md:pb-0"
            >
                <AppSidebarHeader
                    breadcrumbs={breadcrumbs}
                    mobileLeading={mobileLeading}
                />
                {children}
            </AppContent>
        </AppShell>
    );
}

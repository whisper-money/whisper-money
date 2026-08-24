import AppLogoIcon from '@/components/app-logo-icon';
import { type PropsWithChildren } from 'react';

/**
 * The shell for the paid path (the paywall, the checkout success screen). Same
 * header, safe-area handling and full-height column as the onboarding, without
 * its progress hairline: neither of these screens is a step in a flow, and a
 * progress bar on a paywall would be measuring nothing.
 */
export default function SubscriptionLayout({ children }: PropsWithChildren) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="pt-safe flex min-h-14 shrink-0 items-center px-5 md:min-h-18 md:px-7">
                <AppLogoIcon className="size-6 fill-current text-foreground" />
            </header>

            {children}
        </div>
    );
}

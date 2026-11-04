import AppLogoIcon from '@/components/app-logo-icon';
import { logout } from '@/routes';
import { edit as deleteAccountEdit } from '@/routes/delete-account';
import { billing } from '@/routes/settings';
import { __ } from '@/utils/i18n';
import { Link, router } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';

/**
 * The shell for the paid path (the paywall, the checkout success screen). Same
 * header, safe-area handling and full-height column as the onboarding, without
 * its progress hairline: neither of these screens is a step in a flow, and a
 * progress bar on a paywall would be measuring nothing.
 *
 * `withAccountLinks` puts the way out of the paywall in the header. A user the
 * paywall holds (a bank connected, no plan) is sent back to it from every app
 * page, so without these they could not reach billing to cancel a subscription,
 * delete their account or even sign out.
 */
export default function SubscriptionLayout({
    children,
    withAccountLinks = false,
}: PropsWithChildren<{ withAccountLinks?: boolean }>) {
    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="pt-safe flex min-h-14 shrink-0 items-center justify-between gap-4 px-5 md:min-h-18 md:px-7">
                <AppLogoIcon className="size-6 fill-current text-foreground" />

                {withAccountLinks && (
                    <nav className="flex flex-wrap items-center justify-end gap-x-4 gap-y-1 text-sm text-muted-foreground">
                        <Link
                            href={billing()}
                            className="hover:text-foreground"
                        >
                            {__('Manage billing')}
                        </Link>
                        <Link
                            href={deleteAccountEdit()}
                            className="hover:text-foreground"
                        >
                            {__('Delete account')}
                        </Link>
                        <Link
                            href={logout()}
                            as="button"
                            onClick={() => router.flushAll()}
                            className="hover:text-foreground"
                        >
                            {__('Log out')}
                        </Link>
                    </nav>
                )}
            </header>

            {children}
        </div>
    );
}

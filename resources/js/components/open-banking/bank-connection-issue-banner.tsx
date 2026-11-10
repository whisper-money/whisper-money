import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { joinNames } from '@/lib/join-names';
import { leavePage } from '@/lib/leave-page';
import { captureEvent } from '@/lib/posthog';
import { index as connectionsIndex } from '@/routes/settings/connections';
import type { BankConnectionIssue, SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import { TriangleAlertIcon, XIcon } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

const DISMISSED_KEY = 'bank-issue-banner-dismissed';
const VIEWED_KEY = 'bank-issue-banner-viewed';

/**
 * What a dismissal hides: the broken connections it was shown for, on the day
 * it was clicked. A new day, or another connection breaking, brings it back.
 */
function dismissalFingerprint(issues: BankConnectionIssue[]): string {
    const today = new Date().toLocaleDateString('en-CA');
    const ids = issues
        .map((issue) => issue.id)
        .sort()
        .join(',');

    return `${today}|${ids}`;
}

function readStorage(storage: () => Storage, key: string): string | null {
    try {
        return storage().getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(storage: () => Storage, key: string, value: string) {
    try {
        storage().setItem(key, value);
    } catch {
        // Private mode or a full quota: the banner simply shows again.
    }
}

/**
 * Warns, on every authenticated page, that a bank connection stopped syncing
 * until the user reconnects it: an expired consent, or an error the scheduled
 * sync gave up retrying. Left alone, the data goes stale without a word, so
 * this replaces the one-off toast that used to be the only sign in the app.
 *
 * One connection gets its own way back (the bank's consent flow, or the
 * connections page for an API-key provider, whose credentials are updated
 * there); several send the user to the connections page to pick.
 *
 * Dismissible for the rest of the day, since a bank the user cannot reach
 * right now should not block every page; it returns the next day, or as soon
 * as another connection breaks, for as long as the problem lasts. It stays off
 * the connections page, which already shows each connection's state.
 */
export function BankConnectionIssueBanner() {
    const {
        url,
        props: { bankConnectionIssues },
    } = usePage<SharedData>();
    const issues = useMemo(
        () => bankConnectionIssues ?? [],
        [bankConnectionIssues],
    );
    const fingerprint = dismissalFingerprint(issues);
    const onConnectionsPage = url.split('?')[0] === connectionsIndex.url();

    // Unknown until mounted: storage does not exist during SSR.
    const [dismissedFingerprint, setDismissedFingerprint] = useState<
        string | null | undefined
    >(undefined);

    useEffect(() => {
        setDismissedFingerprint(
            readStorage(() => window.localStorage, DISMISSED_KEY),
        );
    }, []);

    const visible =
        issues.length > 0 &&
        !onConnectionsPage &&
        dismissedFingerprint !== undefined &&
        dismissedFingerprint !== fingerprint;

    useEffect(() => {
        if (
            !visible ||
            readStorage(() => window.sessionStorage, VIEWED_KEY) !== null
        ) {
            return;
        }

        writeStorage(() => window.sessionStorage, VIEWED_KEY, '1');
        captureEvent('bank_issue_banner_viewed', {
            connections_count: issues.length,
            providers: issues.map((issue) => issue.provider),
            reasons: issues.map((issue) => issue.reason),
        });
    }, [visible, issues]);

    if (!visible) {
        return null;
    }

    const single = issues.length === 1 ? issues[0] : null;

    const reconnect = () => {
        captureEvent('bank_issue_banner_reconnect_clicked', {
            connections_count: issues.length,
            destination: single?.reconnect_url ? 'bank' : 'connections_page',
        });

        if (single?.reconnect_url) {
            // The reconnect route redirects to the bank, which Inertia cannot follow.
            leavePage(single.reconnect_url);

            return;
        }

        router.visit(connectionsIndex.url());
    };

    const dismiss = () => {
        writeStorage(() => window.localStorage, DISMISSED_KEY, fingerprint);
        setDismissedFingerprint(fingerprint);
    };

    return (
        <div className="mx-auto w-full max-w-page px-5 pb-4 sm:px-6">
            <Alert className="border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-100 [&>svg]:text-amber-600 dark:[&>svg]:text-amber-400">
                <TriangleAlertIcon />
                <AlertTitle className="line-clamp-none pr-8">
                    {single
                        ? __('Your :bank connection stopped syncing.', {
                              bank: single.aspsp_name,
                          })
                        : __(':banks stopped syncing.', {
                              banks: joinNames(
                                  issues.map((issue) => issue.aspsp_name),
                              ),
                          })}
                </AlertTitle>
                <AlertDescription className="text-amber-900/80 dark:text-amber-100/80">
                    <p>
                        {__(
                            'Your balances and transactions are not updating. Reconnect to resume automatic syncing.',
                        )}
                    </p>
                    <Button size="sm" className="mt-1" onClick={reconnect}>
                        {single ? __('Reconnect') : __('Review connections')}
                    </Button>
                </AlertDescription>
                <Button
                    size="icon"
                    variant="ghost"
                    className="absolute top-1.5 right-1.5 size-8 text-amber-900/70 hover:bg-amber-500/15 hover:text-amber-900 dark:text-amber-100/70 dark:hover:text-amber-100"
                    onClick={dismiss}
                    aria-label={__('Dismiss')}
                >
                    <XIcon className="size-4" />
                </Button>
            </Alert>
        </div>
    );
}

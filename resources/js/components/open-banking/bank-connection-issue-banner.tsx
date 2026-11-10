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

type Dismissal = { day: string; ids: string[] };

/**
 * Whether a dismissal still covers what is broken: it was clicked today, for
 * every connection that is broken now. A new day, or another connection
 * breaking, brings the banner back; fixing one of several does not.
 */
function isCoveredBy(
    dismissal: Dismissal | null,
    issues: BankConnectionIssue[],
): boolean {
    return (
        dismissal !== null &&
        dismissal.day === localDateKey(new Date()) &&
        issues.every((issue) => dismissal.ids.includes(issue.id))
    );
}

function parseDismissal(raw: string | null): Dismissal | null {
    try {
        const parsed = raw ? JSON.parse(raw) : null;

        return typeof parsed?.day === 'string' && Array.isArray(parsed?.ids)
            ? parsed
            : null;
    } catch {
        return null;
    }
}

/** The local calendar day as YYYY-MM-DD, so "today" ends at the user's midnight. */
function localDateKey(date: Date): string {
    const pad = (value: number) => String(value).padStart(2, '0');

    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
}

type StorageArea = 'localStorage' | 'sessionStorage';

function readStorage(area: StorageArea, key: string): string | null {
    try {
        return window[area].getItem(key);
    } catch {
        return null;
    }
}

function writeStorage(area: StorageArea, key: string, value: string) {
    try {
        window[area].setItem(key, value);
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
 * A single Enable Banking connection gets a Reconnect straight into the bank's
 * consent flow. An API-key provider is fixed on the connections page (new
 * credentials, or a sync), and several connections send the user there to
 * pick, so those get "Review connections" instead.
 *
 * Dismissible for the rest of the day, since a bank the user cannot reach
 * right now should not block every page; it returns the next day, or as soon
 * as another connection breaks, for as long as the problem lasts. It stays off
 * the connections page, which already shows each connection's state.
 */
export function BankConnectionIssueBanner() {
    const {
        url,
        props: { bankConnectionIssues, auth },
    } = usePage<SharedData>();
    // Per user, so one person's dismissal on a shared browser hides nothing for another.
    const dismissedKey = `${DISMISSED_KEY}:${auth.user?.id ?? 'guest'}`;
    const viewedKey = `${VIEWED_KEY}:${auth.user?.id ?? 'guest'}`;
    const issues = useMemo(
        () => bankConnectionIssues ?? [],
        [bankConnectionIssues],
    );
    const onConnectionsPage = url.split('?')[0] === connectionsIndex.url();

    // Unknown until mounted: storage does not exist during SSR.
    const [dismissal, setDismissal] = useState<Dismissal | null | undefined>(
        undefined,
    );

    useEffect(() => {
        setDismissal(parseDismissal(readStorage('localStorage', dismissedKey)));
    }, [dismissedKey]);

    const visible =
        issues.length > 0 &&
        !onConnectionsPage &&
        dismissal !== undefined &&
        !isCoveredBy(dismissal, issues);

    useEffect(() => {
        if (!visible || readStorage('sessionStorage', viewedKey) !== null) {
            return;
        }

        writeStorage('sessionStorage', viewedKey, '1');
        captureEvent('bank_issue_banner_viewed', {
            connections_count: issues.length,
            providers: issues.map((issue) => issue.provider),
            reasons: issues.map((issue) => issue.reason),
        });
    }, [visible, issues, viewedKey]);

    if (!visible) {
        return null;
    }

    const single = issues.length === 1 ? issues[0] : null;
    // Only an Enable Banking connection has a consent to renew; anything else is
    // fixed on the connections page, and the button says so.
    const reconnectUrl = single?.reconnect_url ?? null;

    const reconnect = () => {
        captureEvent('bank_issue_banner_reconnect_clicked', {
            connections_count: issues.length,
            destination: reconnectUrl ? 'bank' : 'connections_page',
        });

        if (reconnectUrl) {
            // The reconnect route redirects to the bank, which Inertia cannot follow.
            leavePage(reconnectUrl);

            return;
        }

        router.visit(connectionsIndex.url());
    };

    const dismiss = () => {
        const dismissed: Dismissal = {
            day: localDateKey(new Date()),
            ids: issues.map((issue) => issue.id),
        };

        writeStorage('localStorage', dismissedKey, JSON.stringify(dismissed));
        setDismissal(dismissed);
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
                        {reconnectUrl
                            ? __(
                                  'Your balances and transactions are not updating. Reconnect to resume automatic syncing.',
                              )
                            : __(
                                  'Your balances and transactions are not updating. Review your connections to get them syncing again.',
                              )}
                    </p>
                    <Button size="sm" className="mt-1" onClick={reconnect}>
                        {reconnectUrl
                            ? __('Reconnect')
                            : __('Review connections')}
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

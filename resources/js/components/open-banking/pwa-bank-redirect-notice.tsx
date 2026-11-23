import { BetaConnectorNotice } from '@/components/open-banking/beta-connector';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useClipboard } from '@/hooks/use-clipboard';
import { leavePage } from '@/lib/leave-page';
import {
    absoluteUrl,
    browserHandoff,
    chromeIntentUrl,
} from '@/lib/open-in-browser';
import { captureEvent } from '@/lib/posthog';
import { detectMobilePlatform, isInstalledPwa } from '@/lib/pwa';
import { __ } from '@/utils/i18n';
import { Copy, ExternalLink, Smartphone } from 'lucide-react';
import { type ReactNode, useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';

type BankRedirectFlow = 'connect' | 'reconnect';

type BankRedirectChoice = 'open_in_browser' | 'copy_link' | 'here_anyway';

/**
 * Whether the page runs as the app installed on a phone, where the bank's
 * return gets lost. A desktop install keeps the round trip in one window, and
 * the notice's copy (phone, Safari) would not fit it. False until mounted, so
 * the server render and the first client render agree.
 */
function useInstalledMobilePwa(): boolean {
    const [installed, setInstalled] = useState(false);

    useEffect(() => {
        setInstalled(isInstalledPwa() && detectMobilePlatform() !== null);
    }, []);

    return installed;
}

/** What the user did with the notice: leave for the browser, or stay. */
function captureBankRedirectChoice(
    flow: BankRedirectFlow,
    choice: BankRedirectChoice,
): void {
    captureEvent('pwa_bank_redirect_choice', { flow, choice });
}

/**
 * Warns a user in the installed app before the bank redirect. The bank's way
 * back often lands in another browser window (Android especially), where the
 * authorization session is gone and our callback never fires, so the
 * connection stays pending. We cannot fix the bank's return; we can send the
 * user to the browser, where the round trip stays in one tab.
 *
 * `browserUrl` is where the browser should start: the reconnect route for an
 * existing connection, or by default the page itself, where the user picks
 * the bank again. Never blocks: the caller keeps a "here anyway" action.
 */
export function PwaBankRedirectNotice({
    browserUrl,
    flow,
}: {
    browserUrl?: string;
    flow: BankRedirectFlow;
}) {
    const [, copy] = useClipboard();
    const handoff = browserHandoff(detectMobilePlatform());

    const handOver = async () => {
        const url = absoluteUrl(browserUrl ?? window.location.href);

        if (handoff === 'open') {
            captureBankRedirectChoice(flow, 'open_in_browser');
            leavePage(chromeIntentUrl(url));

            return;
        }

        captureBankRedirectChoice(flow, 'copy_link');

        if (await copy(url)) {
            toast.success(__('Link copied. Paste it in Safari.'));
        } else {
            toast.error(__('Could not copy the link.'));
        }
    };

    return (
        <Alert variant="warning">
            <Smartphone />
            <AlertTitle>{__('Better from the browser')}</AlertTitle>
            <AlertDescription>
                <p>
                    {__(
                        "You're in the installed app. When you come back from your bank, your phone may open another window and the connection gets lost.",
                    )}
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="outline"
                    className="mt-1"
                    onClick={handOver}
                >
                    {handoff === 'open' ? (
                        <>
                            <ExternalLink />
                            {__('Open in browser')}
                        </>
                    ) : (
                        <>
                            <Copy />
                            {__('Copy link')}
                        </>
                    )}
                </Button>
            </AlertDescription>
        </Alert>
    );
}

/**
 * The PWA notice for a new connection's confirm step. `notice` is null outside
 * the installed app; callers run `recordConnectHere` before their own
 * authorize so the "here anyway" choice is recorded.
 */
export function usePwaConnectNotice(): {
    warn: boolean;
    notice: ReactNode;
    recordConnectHere: () => void;
} {
    const warn = useInstalledMobilePwa();

    const recordConnectHere = useCallback(() => {
        if (warn) {
            captureBankRedirectChoice('connect', 'here_anyway');
        }
    }, [warn]);

    return {
        warn,
        notice: warn ? <PwaBankRedirectNotice flow="connect" /> : null,
        recordConnectHere,
    };
}

interface ReconnectRequest {
    bankName: string;
    browserUrl: string;
    beta?: boolean;
    reconnectHere: () => void;
}

/**
 * Puts the PWA notice in front of every Reconnect: in the installed app,
 * `requestReconnect` opens a dialog first; in a browser it goes straight to
 * the bank as before. Render `dialog` anywhere in the caller's tree.
 */
export function usePwaReconnectGate(): {
    requestReconnect: (request: ReconnectRequest) => void;
    reconnectDialog: ReactNode;
} {
    const installed = useInstalledMobilePwa();
    const [pending, setPending] = useState<ReconnectRequest | null>(null);

    const requestReconnect = useCallback(
        (request: ReconnectRequest) => {
            if (installed) {
                setPending(request);

                return;
            }

            request.reconnectHere();
        },
        [installed],
    );

    const reconnectHere = () => {
        if (!pending) {
            return;
        }

        captureBankRedirectChoice('reconnect', 'here_anyway');
        setPending(null);
        pending.reconnectHere();
    };

    const reconnectDialog = (
        <Dialog
            open={pending !== null}
            onOpenChange={(open) => !open && setPending(null)}
        >
            {pending && (
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {__('Reconnect :bank', { bank: pending.bankName })}
                        </DialogTitle>
                        <DialogDescription>
                            {__("We'll take you to your bank to renew access.")}
                        </DialogDescription>
                    </DialogHeader>
                    {pending.beta && <BetaConnectorNotice />}
                    <PwaBankRedirectNotice
                        browserUrl={pending.browserUrl}
                        flow="reconnect"
                    />
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setPending(null)}
                        >
                            {__('Cancel')}
                        </Button>
                        <Button onClick={reconnectHere}>
                            {__('Reconnect here anyway')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            )}
        </Dialog>
    );

    return { requestReconnect, reconnectDialog };
}

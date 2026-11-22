import { AMBER } from '@/components/open-banking/beta-connector';
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

export type BankRedirectFlow = 'connect' | 'reconnect';

type BankRedirectChoice = 'open_in_browser' | 'copy_link' | 'here_anyway';

/**
 * Whether the page runs as the installed app. False until mounted, so the
 * server render and the first client render agree.
 */
export function useInstalledPwa(): boolean {
    const [installed, setInstalled] = useState(false);

    useEffect(() => {
        setInstalled(isInstalledPwa());
    }, []);

    return installed;
}

/** What the user did with the notice: leave for the browser, or stay. */
export function captureBankRedirectChoice(
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
 * `browserUrl` is where the browser should start: the page itself for a new
 * connection (the user picks the bank again), the reconnect route for an
 * existing one. Never blocks: the caller keeps a "here anyway" action.
 */
export function PwaBankRedirectNotice({
    browserUrl,
    flow,
}: {
    browserUrl: string;
    flow: BankRedirectFlow;
}) {
    const [, copy] = useClipboard();
    const handoff = browserHandoff(detectMobilePlatform());

    const handOver = async () => {
        const url = absoluteUrl(browserUrl);

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
        <Alert className={AMBER}>
            <Smartphone />
            <AlertTitle>{__('Better from the browser')}</AlertTitle>
            <AlertDescription className="text-amber-700 dark:text-amber-300">
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

interface ReconnectRequest {
    bankName: string;
    browserUrl: string;
    proceed: () => void;
}

/**
 * Puts the PWA notice in front of every Reconnect: in the installed app,
 * `requestReconnect` opens a dialog first; in a browser it goes straight to
 * the bank as before. Render `dialog` anywhere in the caller's tree.
 */
export function usePwaReconnectGate(): {
    requestReconnect: (request: ReconnectRequest) => void;
    dialog: ReactNode;
} {
    const installed = useInstalledPwa();
    const [pending, setPending] = useState<ReconnectRequest | null>(null);

    const requestReconnect = useCallback(
        (request: ReconnectRequest) => {
            if (installed) {
                setPending(request);

                return;
            }

            request.proceed();
        },
        [installed],
    );

    const reconnectHere = () => {
        if (!pending) {
            return;
        }

        captureBankRedirectChoice('reconnect', 'here_anyway');
        setPending(null);
        pending.proceed();
    };

    const dialog = (
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

    return { requestReconnect, dialog };
}

import { useCallback, useMemo, useState } from 'react';

/**
 * Hands a picture to whatever the device shares with.
 *
 * On a phone this is the real OS sheet — Instagram, WhatsApp, AirDrop, the
 * camera roll — because the Web Share API's file arm is what iOS Safari and
 * Chrome for Android put behind `navigator.share`. There is no native wrapper
 * around this app, so that API *is* the native behaviour.
 *
 * Two things about it decide the shape of this hook:
 *
 * `navigator.share` exists in desktop Chrome without being able to carry files,
 * so the only honest feature test is `canShare({ files })` with a real File in
 * hand. Anything less offers a button that throws when pressed.
 *
 * And it may only run inside the user gesture that asked for it. An `await`
 * long enough to lose that gesture gets `NotAllowedError` on iOS, so the blob
 * has to be there already: callers pass the same URL the dialog has been
 * painting as a preview, which leaves the PNG in the HTTP cache and turns the
 * fetch into a cache read. Rendering it lazily behind the button would be the
 * obvious design and it would fail on exactly the devices this exists for.
 */
export type ShareOutcome = 'shared' | 'dismissed' | 'unsupported' | 'failed';

function probeFileSharing(): boolean {
    if (
        typeof navigator === 'undefined' ||
        typeof navigator.canShare !== 'function'
    ) {
        return false;
    }

    try {
        return navigator.canShare({
            files: [new File([''], 'probe.png', { type: 'image/png' })],
        });
    } catch {
        // Some engines throw on a File they cannot construct rather than
        // answering false.
        return false;
    }
}

export function useShareImage() {
    const [sharing, setSharing] = useState(false);
    const canShareFiles = useMemo(probeFileSharing, []);

    const shareImage = useCallback(
        async ({
            url,
            filename,
            title,
        }: {
            url: string;
            filename: string;
            title?: string;
        }): Promise<ShareOutcome> => {
            if (!canShareFiles) {
                return 'unsupported';
            }

            setSharing(true);

            try {
                const response = await fetch(url);

                if (!response.ok) {
                    return 'failed';
                }

                const file = new File([await response.blob()], filename, {
                    type: 'image/png',
                });

                // Re-checked with the real file: a picture too big for the
                // target would otherwise throw out of `share()`.
                if (!navigator.canShare({ files: [file] })) {
                    return 'unsupported';
                }

                await navigator.share({ files: [file], title });

                return 'shared';
            } catch (error) {
                // Closing the sheet without picking anything rejects with
                // AbortError. That is a person changing their mind, not a
                // failure, and it must not raise an error at them.
                if (
                    error instanceof DOMException &&
                    error.name === 'AbortError'
                ) {
                    return 'dismissed';
                }

                return 'failed';
            } finally {
                setSharing(false);
            }
        },
        [canShareFiles],
    );

    return { canShareFiles, sharing, shareImage };
}

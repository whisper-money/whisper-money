import { act, renderHook } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { useShareImage } from './use-share-image';

/*
 * Handing a picture to the OS share sheet.
 *
 * Two things here are easy to get wrong and impossible to see in a browser that
 * happens to support everything: a desktop Chrome that has `navigator.share`
 * but cannot carry files must not be offered the button, and closing the sheet
 * without choosing anything must not be reported as a failure.
 */

function stubShare({
    canShare = true,
    share = vi.fn().mockResolvedValue(undefined),
}: { canShare?: boolean | (() => boolean); share?: () => Promise<void> } = {}) {
    Object.assign(navigator, {
        canShare: typeof canShare === 'function' ? canShare : () => canShare,
        share,
    });

    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({
            ok: true,
            blob: () =>
                Promise.resolve(new Blob(['png'], { type: 'image/png' })),
        }),
    );

    return share;
}

afterEach(() => {
    vi.unstubAllGlobals();
    // @ts-expect-error — putting the navigator back the way it was found.
    delete navigator.canShare;
    // @ts-expect-error — same.
    delete navigator.share;
});

const picture = {
    url: '/progress/medal/streaks.2/feed/light?preview=1&amount=1',
    filename: 'medal.png',
};

describe('useShareImage', () => {
    it('offers the sheet only when files can actually ride on it', () => {
        stubShare({ canShare: false });
        expect(
            renderHook(() => useShareImage()).result.current.canShareFiles,
        ).toBe(false);

        stubShare({ canShare: true });
        expect(
            renderHook(() => useShareImage()).result.current.canShareFiles,
        ).toBe(true);
    });

    it('says so rather than throwing when there is no sheet at all', async () => {
        vi.stubGlobal('fetch', vi.fn());
        const { result } = renderHook(() => useShareImage());

        await act(async () => {
            expect(await result.current.shareImage(picture)).toBe(
                'unsupported',
            );
        });

        // Nothing was fetched: there was nothing to hand it to.
        expect(fetch).not.toHaveBeenCalled();
    });

    it('hands the sheet a PNG named for the medal', async () => {
        const share = stubShare();
        const { result } = renderHook(() => useShareImage());

        await act(async () => {
            expect(await result.current.shareImage(picture)).toBe('shared');
        });

        expect(fetch).toHaveBeenCalledWith(picture.url);

        const [{ files }] = (share as ReturnType<typeof vi.fn>).mock.calls[0];
        expect(files[0].name).toBe('medal.png');
        expect(files[0].type).toBe('image/png');
    });

    it('reads a closed sheet as a change of mind, not a failure', async () => {
        stubShare({
            share: vi
                .fn()
                .mockRejectedValue(new DOMException('cancelled', 'AbortError')),
        });
        const { result } = renderHook(() => useShareImage());

        await act(async () => {
            expect(await result.current.shareImage(picture)).toBe('dismissed');
        });
    });

    it('reports a real failure as one', async () => {
        stubShare({ share: vi.fn().mockRejectedValue(new Error('boom')) });
        const { result } = renderHook(() => useShareImage());

        await act(async () => {
            expect(await result.current.shareImage(picture)).toBe('failed');
        });
    });

    it('does not share a picture that never arrived', async () => {
        stubShare();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false }));
        const { result } = renderHook(() => useShareImage());

        await act(async () => {
            expect(await result.current.shareImage(picture)).toBe('failed');
        });

        expect(navigator.share).not.toHaveBeenCalled();
    });
});

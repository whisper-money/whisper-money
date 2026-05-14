import { describe, expect, it, vi } from 'vitest';
import {
    isChunkLoadError,
    reloadOnChunkLoadError,
} from './chunk-load-recovery';

describe('isChunkLoadError', () => {
    it('detects Vite dynamic import fetch failures', () => {
        expect(
            isChunkLoadError(
                new TypeError(
                    'Failed to fetch dynamically imported module: https://whisper.money/build/assets/accounts-BO3xxENF.js',
                ),
            ),
        ).toBe(true);
    });

    it('detects Vite CSS preload failures', () => {
        expect(
            isChunkLoadError(
                new Error(
                    'Unable to preload CSS for /build/assets/app-BpqbLMXP.css',
                ),
            ),
        ).toBe(true);
    });

    it('ignores unrelated errors', () => {
        expect(isChunkLoadError(new Error('Validation failed'))).toBe(false);
    });
});

describe('reloadOnChunkLoadError', () => {
    it('reloads once per asset signature', () => {
        const reload = vi.fn();
        const storage = window.sessionStorage;
        storage.clear();

        const reason = new TypeError(
            'Failed to fetch dynamically imported module: https://whisper.money/build/assets/accounts-BO3xxENF.js',
        );

        expect(
            reloadOnChunkLoadError(reason, {
                assetSignature: 'app-old.js',
                reload,
                storage,
            }),
        ).toBe(true);
        expect(
            reloadOnChunkLoadError(reason, {
                assetSignature: 'app-old.js',
                reload,
                storage,
            }),
        ).toBe(false);
        expect(reload).toHaveBeenCalledOnce();
    });

    it('does not reload for unrelated errors', () => {
        const reload = vi.fn();

        expect(
            reloadOnChunkLoadError(new Error('Validation failed'), {
                assetSignature: 'app-old.js',
                reload,
                storage: window.sessionStorage,
            }),
        ).toBe(false);
        expect(reload).not.toHaveBeenCalled();
    });
});

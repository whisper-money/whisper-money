import { describe, expect, it, vi } from 'vitest';
import { addMediaQueryListener, removeMediaQueryListener } from './media-query';

function fakeMql(overrides: Partial<MediaQueryList>): MediaQueryList {
    return { matches: false, media: '', ...overrides } as MediaQueryList;
}

describe('addMediaQueryListener', () => {
    it('uses the modern addEventListener when available', () => {
        const addEventListener = vi.fn();
        const handler = () => {};

        addMediaQueryListener(fakeMql({ addEventListener }), handler);

        expect(addEventListener).toHaveBeenCalledWith('change', handler);
    });

    it('falls back to the deprecated addListener on Safari <14', () => {
        const addListener = vi.fn();
        const handler = () => {};

        // addEventListener undefined mirrors old Safari, where the modern API is
        // missing and the previous code threw at boot.
        addMediaQueryListener(
            fakeMql({ addEventListener: undefined, addListener }),
            handler,
        );

        expect(addListener).toHaveBeenCalledWith(handler);
    });

    it('does not throw when neither API is present', () => {
        expect(() =>
            addMediaQueryListener(
                fakeMql({
                    addEventListener: undefined,
                    addListener: undefined,
                }),
                () => {},
            ),
        ).not.toThrow();
    });
});

describe('removeMediaQueryListener', () => {
    it('uses the modern removeEventListener when available', () => {
        const removeEventListener = vi.fn();
        const handler = () => {};

        removeMediaQueryListener(fakeMql({ removeEventListener }), handler);

        expect(removeEventListener).toHaveBeenCalledWith('change', handler);
    });

    it('falls back to the deprecated removeListener on Safari <14', () => {
        const removeListener = vi.fn();
        const handler = () => {};

        removeMediaQueryListener(
            fakeMql({ removeEventListener: undefined, removeListener }),
            handler,
        );

        expect(removeListener).toHaveBeenCalledWith(handler);
    });
});

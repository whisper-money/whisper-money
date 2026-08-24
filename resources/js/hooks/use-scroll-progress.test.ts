import { act, renderHook } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useScrollProgress } from './use-scroll-progress';

/**
 * Place the element's box so that the hook sees it at a known position, then
 * fire the scroll listener the hook attached.
 */
function positionElement(
    element: HTMLElement | null,
    { top, height }: { top: number; height: number },
) {
    vi.spyOn(element as HTMLElement, 'getBoundingClientRect').mockReturnValue({
        top,
        height,
    } as DOMRect);

    act(() => {
        window.dispatchEvent(new Event('scroll'));
    });
}

describe('useScrollProgress', () => {
    beforeEach(() => {
        window.innerHeight = 800;
    });

    it('stays at 0 until the element enters from the bottom', () => {
        const { result } = renderHook(() =>
            useScrollProgress<HTMLDivElement>(),
        );
        result.current.ref.current = document.createElement('div');

        // Element still below the fold: top beyond the viewport height.
        positionElement(result.current.ref.current, { top: 900, height: 200 });

        expect(result.current.progress).toBe(0);
    });

    it('reaches 1 once the element has passed above the viewport', () => {
        const { result } = renderHook(() =>
            useScrollProgress<HTMLDivElement>(),
        );
        result.current.ref.current = document.createElement('div');

        positionElement(result.current.ref.current, { top: -400, height: 200 });

        expect(result.current.progress).toBe(1);
    });

    it('reports how far through the viewport the element has travelled', () => {
        const { result } = renderHook(() =>
            useScrollProgress<HTMLDivElement>(),
        );
        result.current.ref.current = document.createElement('div');

        // (800 - 400) / (800 + 200) = 0.4
        positionElement(result.current.ref.current, { top: 400, height: 200 });

        expect(result.current.progress).toBeCloseTo(0.4);
    });
});

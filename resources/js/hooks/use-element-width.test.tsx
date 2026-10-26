import { act, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { useElementWidth } from './use-element-width';

let measuredWidth = 640;
let resize: (width: number) => void = () => {};
const disconnect = vi.fn();

class FakeResizeObserver {
    constructor(callback: ResizeObserverCallback) {
        resize = (width) => {
            measuredWidth = width;
            callback([], this as unknown as ResizeObserver);
        };
    }

    observe() {}

    disconnect = disconnect;
}

function Measured() {
    const [ref, width] = useElementWidth<HTMLDivElement>();

    return (
        <div ref={ref} data-testid="measured">
            {width === null ? 'unknown' : width}
        </div>
    );
}

beforeEach(() => {
    vi.stubGlobal('ResizeObserver', FakeResizeObserver);
    measuredWidth = 640;
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(
        () => ({ width: measuredWidth }) as DOMRect,
    );
});

afterEach(() => {
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('useElementWidth', () => {
    it('measures the element as soon as it mounts', () => {
        render(<Measured />);

        expect(screen.getByTestId('measured')).toHaveTextContent('640');
    });

    it('follows the element as it is resized, and stops once it unmounts', () => {
        const { unmount } = render(<Measured />);

        act(() => resize(900));

        expect(screen.getByTestId('measured')).toHaveTextContent('900');

        unmount();

        expect(disconnect).toHaveBeenCalled();
    });
});

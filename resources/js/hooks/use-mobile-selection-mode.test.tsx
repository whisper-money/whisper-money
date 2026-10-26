import { type RowSelectionState } from '@tanstack/react-table';
import { act, fireEvent, render, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    LONG_PRESS_MS,
    type RowSelectionMode,
    useLongPressSelect,
    useMobileSelectionMode,
} from './use-mobile-selection-mode';

const isMobile = vi.hoisted(() => ({ value: true }));

vi.mock('@/hooks/use-mobile', () => ({ useIsMobile: () => isMobile.value }));
const haptics = vi.hoisted(() => ({ trigger: () => undefined }));

vi.mock('@/hooks/use-web-haptics', () => ({ useWebHaptics: () => haptics }));

const ROWS = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

function renderSelectionMode() {
    const onClear = vi.fn();

    const hook = renderHook(() => {
        const [rowSelection, setRowSelection] = useState<RowSelectionState>({});
        const selection = useMobileSelectionMode({
            rowSelection,
            setRowSelection,
            rows: ROWS,
            onClear,
        });

        return { selection, rowSelection, setRowSelection };
    });

    return { ...hook, onClear };
}

beforeEach(() => {
    isMobile.value = true;
});

describe('useMobileSelectionMode', () => {
    it('starts with a single row selected', () => {
        const { result } = renderSelectionMode();

        act(() => result.current.selection.enterWith('b'));

        expect(result.current.selection.isActive).toBe(true);
        expect(result.current.rowSelection).toEqual({ b: true });
    });

    it('turns on as soon as a row is ticked from its checkbox', () => {
        const { result } = renderSelectionMode();

        expect(result.current.selection.isActive).toBe(false);

        act(() => result.current.setRowSelection({ c: true }));

        expect(result.current.selection.isActive).toBe(true);
        expect(result.current.selection.selectedCount).toBe(1);
    });

    it('turns off and clears what the page keeps when the last row is unticked', () => {
        const { result, onClear } = renderSelectionMode();

        act(() => result.current.selection.enterWith('a'));
        act(() => result.current.selection.toggleRow('a'));

        expect(result.current.rowSelection).toEqual({});
        expect(result.current.selection.isActive).toBe(false);
        expect(onClear).toHaveBeenCalledOnce();
    });

    it('turns off when the selection is emptied from elsewhere, e.g. a bulk action', () => {
        const { result } = renderSelectionMode();

        act(() => result.current.selection.enterWith('a'));
        act(() => result.current.setRowSelection({}));

        expect(result.current.selection.isActive).toBe(false);
    });

    it('keeps the same row handle while rows are ticked', () => {
        const { result } = renderSelectionMode();

        act(() => result.current.selection.enterWith('a'));
        const forRows = result.current.selection.forRows;
        act(() => result.current.selection.toggleRow('b'));

        expect(result.current.selection.forRows).toBe(forRows);
    });

    it('clears the selection and what the page keeps', () => {
        const { result, onClear } = renderSelectionMode();

        act(() => result.current.selection.enterWith('a'));
        act(() => result.current.selection.clear());

        expect(onClear).toHaveBeenCalledOnce();
        expect(result.current.selection.isActive).toBe(false);
    });

    it('selects every visible row, then none', () => {
        const { result } = renderSelectionMode();

        act(() => result.current.selection.toggleAllVisible());

        expect(result.current.rowSelection).toEqual({
            a: true,
            b: true,
            c: true,
        });
        expect(result.current.selection.allVisibleSelected).toBe(true);

        act(() => result.current.selection.toggleAllVisible());

        expect(result.current.rowSelection).toEqual({});
        expect(result.current.selection.isActive).toBe(false);
    });

    it('is never active off the phone', () => {
        isMobile.value = false;
        const { result } = renderSelectionMode();

        act(() => result.current.selection.enterWith('a'));

        expect(result.current.selection.isMobile).toBe(false);
        expect(result.current.selection.isActive).toBe(false);
    });
});

function selectionStub(
    overrides: Partial<RowSelectionMode> = {},
): RowSelectionMode {
    return {
        isMobile: true,
        isActive: false,
        enterWith: vi.fn(),
        toggleRow: vi.fn(),
        ...overrides,
    };
}

function PressableCard({
    selection,
    onTap,
}: {
    selection: RowSelectionMode;
    onTap: () => void;
}) {
    const longPress = useLongPressSelect('row-1', selection);

    return (
        <div
            data-testid="card"
            {...longPress.handlers}
            onClick={() => {
                if (!longPress.consumeLongPress()) {
                    onTap();
                }
            }}
        >
            Groceries
        </div>
    );
}

describe('useLongPressSelect', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('enters selection mode on a long press, and the lift is not a tap', () => {
        const selection = selectionStub();
        const onTap = vi.fn();
        const { getByTestId } = render(
            <PressableCard selection={selection} onTap={onTap} />,
        );
        const card = getByTestId('card');

        fireEvent.pointerDown(card, { clientX: 10, clientY: 10 });
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));
        fireEvent.pointerUp(card);
        fireEvent.click(card);

        expect(selection.enterWith).toHaveBeenCalledWith('row-1');
        expect(onTap).not.toHaveBeenCalled();
    });

    it('lets a finger that scrolls away cancel the press', () => {
        const selection = selectionStub();
        const { getByTestId } = render(
            <PressableCard selection={selection} onTap={vi.fn()} />,
        );
        const card = getByTestId('card');

        fireEvent.pointerDown(card, { clientX: 10, clientY: 10 });
        fireEvent.pointerMove(card, { clientX: 10, clientY: 40 });
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));

        expect(selection.enterWith).not.toHaveBeenCalled();
    });

    it('treats a short press as a tap', () => {
        const selection = selectionStub();
        const onTap = vi.fn();
        const { getByTestId } = render(
            <PressableCard selection={selection} onTap={onTap} />,
        );
        const card = getByTestId('card');

        fireEvent.pointerDown(card, { clientX: 10, clientY: 10 });
        fireEvent.pointerUp(card);
        fireEvent.click(card);

        expect(onTap).toHaveBeenCalledOnce();
        expect(selection.enterWith).not.toHaveBeenCalled();
    });

    it('does not restart a selection while rows are already being picked', () => {
        const selection = selectionStub({ isActive: true });
        const { getByTestId } = render(
            <PressableCard selection={selection} onTap={vi.fn()} />,
        );

        fireEvent.pointerDown(getByTestId('card'));
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));

        expect(selection.enterWith).not.toHaveBeenCalled();
    });
});

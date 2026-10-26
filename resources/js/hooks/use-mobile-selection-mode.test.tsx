import { type Row, type RowSelectionState } from '@tanstack/react-table';
import { act, fireEvent, render, renderHook } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    LONG_PRESS_MS,
    type RowSelectionMode,
    useMobileSelectionMode,
    useRowSelectionGesture,
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

function GestureRow({
    selection,
    onTap,
}: {
    selection: RowSelectionMode;
    onTap: () => void;
}) {
    const gesture = useRowSelectionGesture(
        { id: 'row-1' } as Row<unknown>,
        selection,
        onTap,
    );

    return (
        <table>
            <tbody>
                <tr data-testid="row" {...gesture.handlers}>
                    <td>Groceries</td>
                </tr>
            </tbody>
        </table>
    );
}

describe('useRowSelectionGesture', () => {
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
            <GestureRow selection={selection} onTap={onTap} />,
        );
        const row = getByTestId('row');

        fireEvent.pointerDown(row, { clientX: 10, clientY: 10 });
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));
        fireEvent.pointerUp(row);
        fireEvent.click(row);

        expect(selection.enterWith).toHaveBeenCalledWith('row-1');
        expect(onTap).not.toHaveBeenCalled();
    });

    it('lets a finger that scrolls away cancel the press', () => {
        const selection = selectionStub();
        const { getByTestId } = render(
            <GestureRow selection={selection} onTap={vi.fn()} />,
        );
        const row = getByTestId('row');

        fireEvent.pointerDown(row, { clientX: 10, clientY: 10 });
        fireEvent.pointerMove(row, { clientX: 10, clientY: 40 });
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));

        expect(selection.enterWith).not.toHaveBeenCalled();
    });

    it('opens the row on a short tap outside selection mode', () => {
        const selection = selectionStub();
        const onTap = vi.fn();
        const { getByTestId } = render(
            <GestureRow selection={selection} onTap={onTap} />,
        );

        fireEvent.click(getByTestId('row'));

        expect(onTap).toHaveBeenCalledOnce();
        expect(selection.toggleRow).not.toHaveBeenCalled();
    });

    it('toggles the row on a tap in selection mode', () => {
        const selection = selectionStub({ isActive: true });
        const onTap = vi.fn();
        const { getByTestId } = render(
            <GestureRow selection={selection} onTap={onTap} />,
        );

        fireEvent.click(getByTestId('row'));

        expect(selection.toggleRow).toHaveBeenCalledWith('row-1');
        expect(onTap).not.toHaveBeenCalled();
    });

    it('leaves the row alone off the phone', () => {
        const selection = selectionStub({ isMobile: false });
        const onTap = vi.fn();
        const { getByTestId } = render(
            <GestureRow selection={selection} onTap={onTap} />,
        );
        const row = getByTestId('row');

        fireEvent.pointerDown(row);
        act(() => vi.advanceTimersByTime(LONG_PRESS_MS));
        fireEvent.click(row);

        expect(selection.enterWith).not.toHaveBeenCalled();
        expect(onTap).toHaveBeenCalledOnce();
    });
});

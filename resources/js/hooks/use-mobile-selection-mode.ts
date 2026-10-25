import { useIsMobile } from '@/hooks/use-mobile';
import { useWebHaptics } from '@/hooks/use-web-haptics';
import { cn } from '@/lib/utils';
import { type Row, type RowSelectionState } from '@tanstack/react-table';
import {
    type Dispatch,
    type MouseEvent,
    type PointerEvent,
    type SetStateAction,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

/** How long a row has to be held before it enters selection mode. */
export const LONG_PRESS_MS = 450;

/** A finger that travels further than this is scrolling, not pressing. */
const LONG_PRESS_TOLERANCE_PX = 10;

/** The part of selection mode a row reads, stable while rows are ticked. */
export interface RowSelectionMode {
    /** Below the `md` breakpoint, where the phone's bar replaces the desktop one. */
    isMobile: boolean;
    /** On the phone and selecting: rows toggle on tap and the bar is up. */
    isActive: boolean;
    /** Enters selection mode with this row as the only one selected. */
    enterWith: (rowId: string) => void;
    toggleRow: (rowId: string) => void;
}

export interface MobileSelectionMode extends RowSelectionMode {
    selectedCount: number;
    allVisibleSelected: boolean;
    enter: () => void;
    /** Leaves selection mode and clears the selection. */
    exit: () => void;
    /** Clears the selection, "all matching filters" included. */
    clear: () => void;
    /** Clears the selection but stays in selection mode. */
    deselectAll: () => void;
    /** Selects every loaded row, or none when they all are already. */
    toggleAllVisible: () => void;
    /** What to hand each row, so ticking one does not re-render them all. */
    forRows: RowSelectionMode;
}

/**
 * Selection on the phone, where there is no checkbox column to reach for: a
 * mode entered by long-pressing a row (or the toolbar's Select button), in
 * which a tap toggles a row instead of opening it.
 *
 * The mode survives the user unticking the last row, so the bar can say "Tap
 * to select", but ends when the selection is emptied from elsewhere: a bulk
 * action that went through, or a filter change.
 */
export function useMobileSelectionMode({
    rowSelection,
    setRowSelection,
    rows,
    onClear,
}: {
    rowSelection: RowSelectionState;
    setRowSelection: Dispatch<SetStateAction<RowSelectionState>>;
    /** The loaded rows, which "Select all visible" ticks. */
    rows: { id: string | number }[];
    /** Clears whatever else the page keeps, e.g. "all matching filters". Stable. */
    onClear?: () => void;
}): MobileSelectionMode {
    const isMobile = useIsMobile();
    const { trigger: haptic } = useWebHaptics();
    const [isSelecting, setIsSelecting] = useState(false);
    const selectedCount = Object.keys(rowSelection).length;
    const latestSelection = useRef(rowSelection);
    const previousCount = useRef(selectedCount);
    const emptiedByUser = useRef(false);

    useEffect(() => {
        latestSelection.current = rowSelection;
    }, [rowSelection]);

    useEffect(() => {
        const emptiedElsewhere =
            previousCount.current > 0 &&
            selectedCount === 0 &&
            !emptiedByUser.current;

        // Off the phone the checkbox column takes over, so the mode must not
        // be waiting there when the viewport shrinks again.
        if (emptiedElsewhere || !isMobile) {
            setIsSelecting(false);
        }

        previousCount.current = selectedCount;
        emptiedByUser.current = false;
    }, [selectedCount, isMobile]);

    const visibleRowIds = useMemo(
        () => rows.map((row) => row.id.toString()),
        [rows],
    );

    const allVisibleSelected =
        visibleRowIds.length > 0 &&
        visibleRowIds.every((rowId) => rowSelection[rowId]);

    const clear = useCallback(() => {
        setRowSelection({});
        onClear?.();
    }, [setRowSelection, onClear]);

    const deselectAll = useCallback(() => {
        // Only a change of count resets the flag, so it is raised only for one.
        if (Object.keys(latestSelection.current).length > 0) {
            emptiedByUser.current = true;
        }
        clear();
    }, [clear]);

    const enter = useCallback(() => setIsSelecting(true), []);

    const enterWith = useCallback(
        (rowId: string) => {
            void haptic('selection');
            setIsSelecting(true);
            setRowSelection({ [rowId]: true });
        },
        [haptic, setRowSelection],
    );

    const exit = useCallback(() => {
        setIsSelecting(false);
        clear();
    }, [clear]);

    const toggleRow = useCallback(
        (rowId: string) => {
            const { [rowId]: wasSelected, ...rest } = latestSelection.current;

            if (!wasSelected) {
                setRowSelection({ ...latestSelection.current, [rowId]: true });
            } else if (Object.keys(rest).length > 0) {
                setRowSelection(rest);
            } else {
                deselectAll();
            }
        },
        [deselectAll, setRowSelection],
    );

    const toggleAllVisible = useCallback(() => {
        if (allVisibleSelected) {
            deselectAll();

            return;
        }

        setRowSelection(
            Object.fromEntries(visibleRowIds.map((rowId) => [rowId, true])),
        );
    }, [allVisibleSelected, deselectAll, setRowSelection, visibleRowIds]);

    const isActive = isMobile && isSelecting;

    const forRows = useMemo(
        () => ({ isMobile, isActive, enterWith, toggleRow }),
        [isMobile, isActive, enterWith, toggleRow],
    );

    return useMemo(
        () => ({
            ...forRows,
            forRows,
            selectedCount,
            allVisibleSelected,
            enter,
            exit,
            clear,
            deselectAll,
            toggleAllVisible,
        }),
        [
            forRows,
            selectedCount,
            allVisibleSelected,
            enter,
            exit,
            clear,
            deselectAll,
            toggleAllVisible,
        ],
    );
}

/**
 * What a table row needs to take part in selection mode: a long press enters
 * it, and once in it a tap toggles the row where it would otherwise open it.
 * Off the phone the row keeps its own click untouched.
 */
export function useRowSelectionGesture<TData>(
    row: Row<TData>,
    selection: RowSelectionMode,
    onTap: (event: MouseEvent<HTMLTableRowElement>) => void,
) {
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);
    const origin = useRef<{ x: number; y: number } | null>(null);
    const longPressed = useRef(false);

    useEffect(() => () => clearTimeout(timer.current), []);

    if (!selection.isMobile) {
        return { className: undefined, handlers: { onClick: onTap } };
    }

    function cancelPress() {
        clearTimeout(timer.current);
        origin.current = null;
    }

    return {
        // Inert cells while selecting: a tap on the category or the row's
        // menu lands on the row and toggles it, rather than opening a picker.
        className: cn(
            'select-none [-webkit-touch-callout:none]',
            selection.isActive && '[&>td>*]:pointer-events-none',
        ),
        handlers: {
            onPointerDown: (event: PointerEvent<HTMLTableRowElement>) => {
                longPressed.current = false;

                if (selection.isActive) {
                    return;
                }

                origin.current = { x: event.clientX, y: event.clientY };
                clearTimeout(timer.current);
                timer.current = setTimeout(() => {
                    longPressed.current = true;
                    selection.enterWith(row.id);
                }, LONG_PRESS_MS);
            },
            onPointerMove: (event: PointerEvent<HTMLTableRowElement>) => {
                if (
                    origin.current &&
                    Math.hypot(
                        event.clientX - origin.current.x,
                        event.clientY - origin.current.y,
                    ) > LONG_PRESS_TOLERANCE_PX
                ) {
                    cancelPress();
                }
            },
            onPointerUp: cancelPress,
            onPointerCancel: cancelPress,
            onPointerLeave: cancelPress,
            onContextMenu: (event: MouseEvent<HTMLTableRowElement>) =>
                event.preventDefault(),
            onClick: (event: MouseEvent<HTMLTableRowElement>) => {
                // The finger lifting off a long press is not a tap.
                if (longPressed.current) {
                    longPressed.current = false;

                    return;
                }

                if (selection.isActive) {
                    selection.toggleRow(row.id);

                    return;
                }

                onTap(event);
            },
        },
    };
}

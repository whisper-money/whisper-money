import type { MoveDirection } from '@/lib/rule-builder-utils';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';

interface AnchoredMove {
    /** Name shared by the items that reorder together, set on each as `data-reorder-list`. */
    list: string;
    /** The moved item, set on it as `data-reorder-id`. */
    id: string;
    direction: MoveDirection;
    /** Read out to screen readers once the move has rendered. */
    announcement: string;
}

const SLIDE_DURATION_MS = 200;
const HIGHLIGHT_DURATION_MS = 1200;

/**
 * Supports moving list items one step at a time with ↑ and ↓ buttons. Call
 * `anchor` right before applying the move: once it renders, the scroll container
 * shifts by however far the item jumped, so the item stays under the pointer and
 * its neighbours slide around it instead. Tapping ↑ again keeps moving the same
 * item rather than whichever one landed under the finger.
 *
 * Each item needs `data-reorder-list` and `data-reorder-id`, and each move button
 * `data-reorder-control="<id>:<direction>"` so keyboard focus can hop to the other
 * button when the pressed one gets disabled at the edge of the list.
 */
export function useAnchoredReorder<TContainer extends HTMLElement>() {
    const containerRef = useRef<TContainer>(null);
    const pendingMove = useRef<{
        move: AnchoredMove;
        topsBefore: Map<string, number>;
        movedByKeyboard: boolean;
    } | null>(null);
    const [highlight, setHighlight] = useState<{ id: string } | null>(null);
    const [announcement, setAnnouncement] = useState({ text: '', count: 0 });

    function itemsOf(list: string): HTMLElement[] {
        return Array.from(
            containerRef.current?.querySelectorAll<HTMLElement>(
                `[data-reorder-list="${list}"]`,
            ) ?? [],
        );
    }

    function anchor(move: AnchoredMove): void {
        pendingMove.current = {
            move,
            topsBefore: new Map(
                itemsOf(move.list).map((item) => [
                    item.dataset.reorderId ?? '',
                    item.getBoundingClientRect().top,
                ]),
            ),
            movedByKeyboard: hasVisibleFocus(document.activeElement),
        };
        setHighlight({ id: move.id });
        setAnnouncement((previous) => ({
            text: move.announcement,
            count: previous.count + 1,
        }));
    }

    useLayoutEffect(() => {
        const pending = pendingMove.current;
        if (!pending) {
            return;
        }
        pendingMove.current = null;

        const { move, topsBefore, movedByKeyboard } = pending;
        const items = itemsOf(move.list);
        const movedItem = items.find(
            (item) => item.dataset.reorderId === move.id,
        );
        if (!movedItem) {
            return;
        }

        const jump =
            movedItem.getBoundingClientRect().top -
            (topsBefore.get(move.id) ?? movedItem.getBoundingClientRect().top);
        if (jump !== 0) {
            scrollContainerOf(movedItem).scrollBy?.({
                top: jump,
                behavior: 'instant',
            });
        }

        if (!prefersReducedMotion()) {
            items.forEach((item) => slideFromPreviousTop(item, topsBefore));
        }

        // A pointer user has nothing to lose here, and moving their focus would
        // pop the other button's tooltip open for no reason.
        const pressed = controlOf(movedItem, move.id, move.direction);
        if (movedByKeyboard && pressed?.disabled) {
            controlOf(movedItem, move.id, opposite(move.direction))?.focus();
        }
    });

    useEffect(() => {
        if (!highlight) {
            return;
        }

        const timeout = setTimeout(
            () => setHighlight(null),
            HIGHLIGHT_DURATION_MS,
        );

        return () => clearTimeout(timeout);
    }, [highlight]);

    return {
        containerRef,
        anchor,
        highlightedId: highlight?.id ?? null,
        // A live region only speaks when its text changes, and two moves can both
        // land on "position 2 of 3"; a no-break space on every other one makes
        // each announcement new.
        announcement:
            announcement.text + (announcement.count % 2 === 1 ? '\u00a0' : ''),
    };
}

function slideFromPreviousTop(
    item: HTMLElement,
    topsBefore: Map<string, number>,
): void {
    const before = topsBefore.get(item.dataset.reorderId ?? '');
    const offset =
        before === undefined ? 0 : before - item.getBoundingClientRect().top;

    if (offset !== 0) {
        item.animate?.(
            [{ transform: `translateY(${offset}px)` }, { transform: 'none' }],
            { duration: SLIDE_DURATION_MS, easing: 'ease-out' },
        );
    }
}

function controlOf(
    item: HTMLElement,
    id: string,
    direction: MoveDirection,
): HTMLButtonElement | null {
    return item.querySelector<HTMLButtonElement>(
        `[data-reorder-control="${id}:${direction}"]`,
    );
}

/** Whether focus shows a ring, which is how a browser tells keyboard focus from a click or tap. */
function hasVisibleFocus(element: Element | null): boolean {
    try {
        return element?.matches(':focus-visible') ?? false;
    } catch {
        // Environments without :focus-visible support throw on the selector.
        return false;
    }
}

function opposite(direction: MoveDirection): MoveDirection {
    return direction === 'up' ? 'down' : 'up';
}

function scrollContainerOf(element: HTMLElement): HTMLElement | Window {
    for (
        let node = element.parentElement;
        node !== null;
        node = node.parentElement
    ) {
        const { overflowY } = getComputedStyle(node);
        if (
            (overflowY === 'auto' || overflowY === 'scroll') &&
            node.scrollHeight > node.clientHeight
        ) {
            return node;
        }
    }

    return window;
}

function prefersReducedMotion(): boolean {
    return (
        window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false
    );
}

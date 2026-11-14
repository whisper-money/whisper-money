import { useElementWidth } from '@/hooks/use-element-width';

/**
 * Below 56rem of room the transactions table's columns no longer fit and it
 * turns into a compact list. Measured on the list, not the viewport: the
 * sidebar takes its share, so a 1024px window with it open already gets the
 * list, and so does a half-width dashboard widget.
 */
export const LIST_LAYOUT_BELOW_PX = 56 * 16; // 56rem at the 16px root size the app keeps

/**
 * Which layout a list of transactions gets for the room it has: a ref for the
 * element it measures, and `null` as the layout until that element is measured.
 */
export function useTransactionLayout<T extends Element>(): [
    ReturnType<typeof useElementWidth<T>>[0],
    'table' | 'list' | null,
] {
    const [ref, width] = useElementWidth<T>();

    if (width === null) {
        return [ref, null];
    }

    return [ref, width < LIST_LAYOUT_BELOW_PX ? 'list' : 'table'];
}

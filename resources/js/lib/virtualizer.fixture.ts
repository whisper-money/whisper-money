/**
 * A stand-in for `useVirtualizer` in tests. jsdom lays nothing out, so the
 * real virtualizer would render no item at all; this one renders every item.
 */
export function useEveryItemVirtualizer({
    count,
    getItemKey,
}: {
    count: number;
    getItemKey: (index: number) => string;
}) {
    return {
        getVirtualItems: () =>
            Array.from({ length: count }, (_, index) => ({
                index,
                key: getItemKey(index),
                start: index * 72,
                end: (index + 1) * 72,
                size: 72,
                lane: 0,
            })),
        getTotalSize: () => count * 72,
        measureElement: () => {},
    };
}

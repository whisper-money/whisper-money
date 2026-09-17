import { router } from '@inertiajs/react';

/**
 * Re-render the page a dialog was opened over, local state included.
 *
 * `router.reload()` is not enough: its options type omits `preserveState`
 * because it always preserves it, and the transactions table copies its rows
 * into component state when it mounts and never reads the prop again. Fresh
 * props would land on a list that goes on showing the rows from before the
 * write. A visit to the same URL remounts the page, so the new rows are there.
 *
 * The scroll position is kept, so the reader stays where they were.
 */
export function refreshPageAfterWrite(): void {
    router.visit(window.location.href, {
        preserveScroll: true,
        preserveState: false,
    });
}

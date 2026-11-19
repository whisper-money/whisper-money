import { show as accountShow } from '@/routes/accounts';
import { show as budgetShow } from '@/routes/budgets';
import { router } from '@inertiajs/react';
import { currentWidgetGesture } from './gesture';
import {
    type OpenTransactionsFilters,
    TRANSACTIONS_USAGE,
    transactionsUrl,
} from './open-transactions';
import { isPlainObject, isUuid, type Resolved } from './open-values';

export type { OpenTransactionsFilters } from './open-transactions';

/**
 * `wm.open(destination)`: takes the user from a widget to one of the app's
 * pages. A widget names where to go, never a URL: an account, a budget, or
 * the transactions list with its filters (a category has no page of its own,
 * so a category goes to its transactions). Like `wm.download`, it only works
 * inside a click on the widget (see gesture.ts), and only where the host lets
 * the widget navigate: never while the dashboard is edited, nor in the MCP
 * preview. It returns whether the visit started, and never throws.
 */
export type OpenDestination =
    | { account: string }
    | { budget: string }
    | { transactions: OpenTransactionsFilters };

interface Destination {
    /** How the usage line writes it. */
    usage: string;
    resolve: (value: unknown) => Resolved;
}

const DESTINATIONS: Record<string, Destination> = {
    account: {
        usage: '{ account: id }',
        resolve: (id) =>
            isUuid(id)
                ? { url: accountShow.url(id) }
                : { error: 'wm.open({ account }) takes an account id.' },
    },
    budget: {
        usage: '{ budget: id }',
        resolve: (id) =>
            isUuid(id)
                ? { url: budgetShow.url(id) }
                : { error: 'wm.open({ budget }) takes a budget id.' },
    },
    transactions: { usage: TRANSACTIONS_USAGE, resolve: transactionsUrl },
};

const USAGE = `wm.open() takes one of ${Object.values(DESTINATIONS)
    .map(({ usage }) => usage)
    .join(', ')}.`;

export function open(destination: unknown): boolean {
    const resolved = resolveDestination(destination);

    if ('error' in resolved) {
        console.warn(`${resolved.error} Nothing was opened.`);

        return false;
    }

    const gesture = currentWidgetGesture();

    if (gesture === null) {
        console.warn(
            'wm.open() only works inside a click on the widget (an onClick on a button, a row or an element); nothing was opened.',
        );

        return false;
    }

    if (!gesture.canNavigate) {
        console.warn(
            'wm.open() does nothing here: links do not open while the dashboard is being edited, nor in a preview.',
        );

        return false;
    }

    router.visit(resolved.url);

    return true;
}

/** The URL of a destination, or why it is not one. */
export function resolveDestination(destination: unknown): Resolved {
    const keys = isPlainObject(destination) ? Object.keys(destination) : [];
    const [key] = keys;

    if (keys.length !== 1 || !Object.hasOwn(DESTINATIONS, key)) {
        return { error: USAGE };
    }

    const value = (destination as Record<string, unknown>)[key];

    return DESTINATIONS[key].resolve(value);
}

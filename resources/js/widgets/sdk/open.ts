import { show as accountShow } from '@/routes/accounts';
import { show as budgetShow } from '@/routes/budgets';
import { router } from '@inertiajs/react';
import { currentWidgetGesture } from './gesture';
import {
    type OpenTransactionsFilters,
    TRANSACTIONS_USAGE,
    transactionsUrl,
} from './open-transactions';
import {
    isPlainObject,
    isUuid,
    type Resolved,
    type WidgetDialogRequest,
} from './open-values';

export type { OpenTransactionsFilters } from './open-transactions';

/**
 * `wm.open(destination)`: takes the user from a widget to one of the app's
 * pages, or opens one of its dialogs over the dashboard. A widget names where
 * to go, never a URL: an account, a budget, or the transactions list with its
 * filters (a category has no page of its own, so a category goes to its
 * transactions); or the new-transaction dialog or the importer, optionally on
 * an account. A dialog only fills in: nothing is saved or imported until the
 * user confirms there. Like `wm.download`, it only works inside a click on the
 * widget (see gesture.ts), and only where the host lets the widget navigate:
 * never while the dashboard is edited, nor in the MCP preview. It returns
 * whether the visit started or the host took the ask for the dialog (which
 * then loads its lists, and may still fail to open with a toast of its own),
 * and never throws.
 */
export type OpenDestination =
    | { account: string }
    | { budget: string }
    | { transactions: OpenTransactionsFilters }
    | { newTransaction: OpenDialogOptions }
    | { import: OpenDialogOptions };

export interface OpenDialogOptions {
    /**
     * The account the dialog starts on. One the user cannot file
     * transactions in (another user's, an investment account…) is left out,
     * and the dialog opens on its usual default.
     */
    accountId?: string;
}

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
    newTransaction: {
        usage: '{ newTransaction: { accountId? } }',
        resolve: (options) => dialogDestination('newTransaction', options),
    },
    import: {
        usage: '{ import: { accountId? } }',
        resolve: (options) => dialogDestination('import', options),
    },
};

/** A dialog destination: `{ accountId? }`, nothing else. */
function dialogDestination(
    name: WidgetDialogRequest['dialog'],
    options: unknown,
): Resolved {
    const error = {
        error: `wm.open({ ${name} }) takes { accountId? }, accountId an account id.`,
    };

    if (!isPlainObject(options)) {
        return error;
    }

    const { accountId, ...rest } = options;

    if (
        Object.keys(rest).length > 0 ||
        (accountId !== undefined && !isUuid(accountId))
    ) {
        return error;
    }

    return { dialog: { dialog: name, accountId: accountId ?? null } };
}

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

    if ('url' in resolved) {
        router.visit(resolved.url);

        return true;
    }

    if (gesture.openDialog === undefined) {
        console.warn(
            'wm.open() cannot open that dialog here; nothing was opened.',
        );

        return false;
    }

    gesture.openDialog(resolved.dialog);

    return true;
}

/** Where a destination leads (a page or a dialog), or why it is nowhere. */
export function resolveDestination(destination: unknown): Resolved {
    const keys = isPlainObject(destination) ? Object.keys(destination) : [];
    const [key] = keys;

    if (keys.length !== 1 || !Object.hasOwn(DESTINATIONS, key)) {
        return { error: USAGE };
    }

    const value = (destination as Record<string, unknown>)[key];

    return DESTINATIONS[key].resolve(value);
}

import { show as accountShow } from '@/routes/accounts';
import { show as budgetShow } from '@/routes/budgets';
import { index as transactionsIndex } from '@/routes/transactions';
import { router } from '@inertiajs/react';
import { currentWidgetGesture } from './gesture';

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

export interface OpenTransactionsFilters {
    categoryIds?: string[];
    accountIds?: string[];
    labelIds?: string[];
    /** `YYYY-MM-DD`. */
    dateFrom?: string;
    /** `YYYY-MM-DD`. */
    dateTo?: string;
    search?: string;
}

type Resolved = { url: string } | { error: string };

type QueryValue = string | string[];

const USAGE =
    'wm.open() takes { account: id }, { budget: id } or { transactions: { categoryIds, accountIds, labelIds, dateFrom, dateTo, search } }';

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

/**
 * The id the transactions filter takes for rows without a category (the
 * pseudo id Transaction::applyFilters reads).
 */
const UNCATEGORIZED = 'uncategorized';

/** The longest search the transactions list accepts. */
const MAX_SEARCH_LENGTH = 200;

const DESTINATIONS: Record<string, (value: unknown) => Resolved> = {
    account: (id) =>
        isUuid(id)
            ? { url: accountShow.url(id) }
            : { error: 'wm.open({ account }) takes an account id.' },
    budget: (id) =>
        isUuid(id)
            ? { url: budgetShow.url(id) }
            : { error: 'wm.open({ budget }) takes a budget id.' },
    transactions: transactionsUrl,
};

const ID_LISTS: Record<
    string,
    [param: string, isId: (id: unknown) => boolean]
> = {
    categoryIds: ['category_ids', (id) => isUuid(id) || id === UNCATEGORIZED],
    accountIds: ['account_ids', isUuid],
    labelIds: ['label_ids', isUuid],
};

const DATES: Record<string, string> = {
    dateFrom: 'date_from',
    dateTo: 'date_to',
};

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
        return { error: `${USAGE}, one of them.` };
    }

    const value = (destination as Record<string, unknown>)[key];

    return DESTINATIONS[key](value);
}

function transactionsUrl(filters: unknown): Resolved {
    if (!isPlainObject(filters)) {
        return {
            error: 'wm.open({ transactions }) takes an object of filters.',
        };
    }

    const query: Record<string, QueryValue> = {};

    for (const [name, value] of Object.entries(filters)) {
        const param = filterParam(name, value);

        if ('error' in param) {
            return param;
        }

        if (param.value !== null) {
            query[param.name] = param.value;
        }
    }

    if (
        typeof query.date_from === 'string' &&
        typeof query.date_to === 'string' &&
        query.date_from > query.date_to
    ) {
        return {
            error: 'wm.open({ transactions }) needs dateFrom on or before dateTo.',
        };
    }

    return { url: transactionsIndex.url({ query }) };
}

/** One filter as its query parameter; a null value leaves it out. */
function filterParam(
    name: string,
    value: unknown,
): { name: string; value: QueryValue | null } | { error: string } {
    if (value === undefined) {
        return { name, value: null };
    }

    if (Object.hasOwn(ID_LISTS, name)) {
        const [param, isId] = ID_LISTS[name];

        return Array.isArray(value) && value.every(isId)
            ? { name: param, value: value.length > 0 ? value : null }
            : {
                  error: `wm.open({ transactions: { ${name} } }) takes a list of ids, as the API answers them.`,
              };
    }

    if (Object.hasOwn(DATES, name)) {
        return isDate(value)
            ? { name: DATES[name], value }
            : {
                  error: `wm.open({ transactions: { ${name} } }) takes a date written YYYY-MM-DD.`,
              };
    }

    if (name === 'search') {
        return typeof value === 'string' && value.length <= MAX_SEARCH_LENGTH
            ? { name, value: value.trim() === '' ? null : value }
            : {
                  error: `wm.open({ transactions: { search } }) takes text of up to ${MAX_SEARCH_LENGTH} characters.`,
              };
    }

    return {
        error: `wm.open({ transactions }) has no "${name}" filter; it takes categoryIds, accountIds, labelIds, dateFrom, dateTo and search.`,
    };
}

function isPlainObject(value: unknown): value is Record<string, unknown> {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        return false;
    }

    const prototype = Object.getPrototypeOf(value);

    return prototype === Object.prototype || prototype === null;
}

function isUuid(value: unknown): value is string {
    return typeof value === 'string' && UUID.test(value);
}

/** A real calendar day written `YYYY-MM-DD`. */
function isDate(value: unknown): value is string {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) {
        return false;
    }

    const date = new Date(`${value}T00:00:00Z`);

    return (
        !Number.isNaN(date.getTime()) &&
        date.toISOString().slice(0, 10) === value
    );
}

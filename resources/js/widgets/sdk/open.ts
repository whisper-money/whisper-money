import { CASHFLOW_BLOCKS } from '@/lib/cashflow-block';
import { show as accountShow } from '@/routes/accounts';
import { show as budgetShow } from '@/routes/budgets';
import { index as transactionsIndex } from '@/routes/transactions';
import { CATEGORY_TYPES, type CategoryType } from '@/types/category';
import { type CashflowBlock } from '@/types/transaction';
import { currencyDecimals, toMajorUnits } from '@/utils/currency';
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

/**
 * The filters the transactions page takes, each optional and combinable. The
 * page shows each one as an active filter, as if the user had picked it.
 */
export interface OpenTransactionsFilters {
    categoryIds?: string[];
    /** Transactions whose category is of these types. */
    categoryTypes?: CategoryType[];
    accountIds?: string[];
    labelIds?: string[];
    /** `YYYY-MM-DD`. */
    dateFrom?: string;
    /** `YYYY-MM-DD`. */
    dateTo?: string;
    /**
     * The rows behind one block of the cashflow screen. Only over a period:
     * it needs both `dateFrom` and `dateTo`.
     */
    cashflowBlock?: CashflowBlock;
    /**
     * Signed bounds (expenses are negative) in the minor units of `currency`,
     * like every amount a widget reads. The list compares the same figure
     * against each transaction in its own currency, as its amount filter does.
     */
    amountMin?: number;
    amountMax?: number;
    /** The currency `amountMin`/`amountMax` are written in; required with them. */
    currency?: string;
    creditorName?: string;
    debtorName?: string;
    /** Only the transactions the AI categorized. */
    categorySource?: 'ai';
    search?: string;
}

type Resolved = { url: string } | { error: string };

type QueryValue = string | string[];

type Query = Record<string, QueryValue>;

type FilterParam =
    | { name: string; value: QueryValue | null }
    | { error: string };

const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const CURRENCY = /^[A-Z]{3}$/i;

/**
 * The id the transactions filter takes for rows without a category (the
 * pseudo id Transaction::applyFilters reads).
 */
const UNCATEGORIZED = 'uncategorized';

/**
 * The category sources the page shows as a filter: its "Only show AI
 * guesses" toggle. The backend takes others, but the page would drop them.
 */
const CATEGORY_SOURCES = ['ai'] as const;

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

const ENUM_LISTS: Record<string, [param: string, values: readonly string[]]> = {
    categoryTypes: ['category_types', CATEGORY_TYPES],
};

const ENUMS: Record<string, [param: string, values: readonly string[]]> = {
    cashflowBlock: ['cashflow_block', CASHFLOW_BLOCKS],
    categorySource: ['category_source', CATEGORY_SOURCES],
};

const DATES: Record<string, string> = {
    dateFrom: 'date_from',
    dateTo: 'date_to',
};

/** Free-text filters, with the longest text the transactions list accepts. */
const TEXTS: Record<string, [param: string, maxLength: number]> = {
    creditorName: ['creditor_name', 255],
    debtorName: ['debtor_name', 255],
    search: ['search', 200],
};

const AMOUNTS: Record<string, string> = {
    amountMin: 'amount_min',
    amountMax: 'amount_max',
};

const FILTER_NAMES = [
    ...Object.keys(ID_LISTS),
    ...Object.keys(ENUM_LISTS),
    ...Object.keys(DATES),
    ...Object.keys(ENUMS),
    ...Object.keys(AMOUNTS),
    'currency',
    ...Object.keys(TEXTS),
];

const USAGE = `wm.open() takes { account: id }, { budget: id } or { transactions: { ${FILTER_NAMES.join(', ')} } }`;

/**
 * What the filters must agree on between them, checked once each is valid on
 * its own: each rule answers why the query is refused, or null.
 */
const QUERY_RULES: ((query: Query) => string | null)[] = [
    (query) =>
        isOrdered(query.date_from, query.date_to)
            ? null
            : 'wm.open({ transactions }) needs dateFrom on or before dateTo.',
    (query) =>
        query.cashflow_block === undefined ||
        (query.date_from !== undefined && query.date_to !== undefined)
            ? null
            : 'wm.open({ transactions: { cashflowBlock } }) needs both dateFrom and dateTo: a cashflow block only exists over a period.',
    (query) =>
        query.amount_min === undefined ||
        query.amount_max === undefined ||
        Number(query.amount_min) <= Number(query.amount_max)
            ? null
            : 'wm.open({ transactions }) needs amountMin at or below amountMax.',
];

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

    const query: Query = {};

    for (const [name, value] of Object.entries(filters)) {
        const param = filterParam(name, value, filters);

        if ('error' in param) {
            return param;
        }

        if (param.value !== null) {
            query[param.name] = param.value;
        }
    }

    const refusal = QUERY_RULES.map((rule) => rule(query)).find(
        (reason) => reason !== null,
    );

    return refusal
        ? { error: refusal }
        : { url: transactionsIndex.url({ query }) };
}

/** One filter as its query parameter; a null value leaves it out. */
function filterParam(
    name: string,
    value: unknown,
    filters: Record<string, unknown>,
): FilterParam {
    if (value === undefined) {
        return { name, value: null };
    }

    const parse = PARSERS.find(([table]) => Object.hasOwn(table, name));

    if (parse === undefined) {
        return {
            error: `wm.open({ transactions }) has no "${name}" filter; it takes ${FILTER_NAMES.join(', ')}.`,
        };
    }

    return parse[1](name, value, filters);
}

type Parser = (
    name: string,
    value: unknown,
    filters: Record<string, unknown>,
) => FilterParam;

/** Each family of filters beside how it reads one of its values. */
const PARSERS: [table: Record<string, unknown>, parse: Parser][] = [
    [ID_LISTS, idListParam],
    [ENUM_LISTS, enumListParam],
    [ENUMS, enumParam],
    [DATES, dateParam],
    [TEXTS, textParam],
    [AMOUNTS, amountParam],
    [{ currency: true }, currencyParam],
];

function idListParam(name: string, value: unknown): FilterParam {
    const [param, isId] = ID_LISTS[name];

    return Array.isArray(value) && value.every(isId)
        ? { name: param, value: value.length > 0 ? value : null }
        : {
              error: `wm.open({ transactions: { ${name} } }) takes a list of ids, as the API answers them.`,
          };
}

function enumListParam(name: string, value: unknown): FilterParam {
    const [param, values] = ENUM_LISTS[name];

    return Array.isArray(value) &&
        value.every((item) => (values as readonly unknown[]).includes(item))
        ? { name: param, value: value.length > 0 ? value : null }
        : {
              error: `wm.open({ transactions: { ${name} } }) takes a list of ${quoted(values)}.`,
          };
}

function enumParam(name: string, value: unknown): FilterParam {
    const [param, values] = ENUMS[name];

    return typeof value === 'string' && values.includes(value)
        ? { name: param, value }
        : {
              error: `wm.open({ transactions: { ${name} } }) takes one of ${quoted(values)}.`,
          };
}

function dateParam(name: string, value: unknown): FilterParam {
    return isDate(value)
        ? { name: DATES[name], value }
        : {
              error: `wm.open({ transactions: { ${name} } }) takes a date written YYYY-MM-DD.`,
          };
}

function textParam(name: string, value: unknown): FilterParam {
    const [param, maxLength] = TEXTS[name];

    return typeof value === 'string' && value.length <= maxLength
        ? { name: param, value: value.trim() === '' ? null : value }
        : {
              error: `wm.open({ transactions: { ${name} } }) takes text of up to ${maxLength} characters.`,
          };
}

/**
 * The list's amount filter reads major units (`12.34`), each transaction in
 * its own currency, while a widget holds minor units (`1234`): the currency
 * the widget names says how many decimals the figure has.
 */
function amountParam(
    name: string,
    value: unknown,
    filters: Record<string, unknown>,
): FilterParam {
    if (!Number.isSafeInteger(value)) {
        return {
            error: `wm.open({ transactions: { ${name} } }) takes a whole number of minor units (cents for EUR), signed like the API's amounts: expenses are negative.`,
        };
    }

    if (!isCurrency(filters.currency)) {
        return {
            error: `wm.open({ transactions: { ${name} } }) needs the currency its minor units are in, e.g. { ${name}: ${String(value)}, currency: 'EUR' }.`,
        };
    }

    const currency = filters.currency.toUpperCase();

    return {
        name: AMOUNTS[name],
        value: toMajorUnits(value as number, currency).toFixed(
            currencyDecimals(currency),
        ),
    };
}

/** The currency only scales the amounts: it is no filter of its own. */
function currencyParam(_name: string, value: unknown): FilterParam {
    return isCurrency(value)
        ? { name: 'currency', value: null }
        : {
              error: 'wm.open({ transactions: { currency } }) takes a currency code, e.g. EUR.',
          };
}

function quoted(values: readonly string[]): string {
    return values.map((value) => `"${value}"`).join(', ');
}

function isOrdered(from: QueryValue | undefined, to: QueryValue | undefined) {
    return typeof from !== 'string' || typeof to !== 'string' || from <= to;
}

function isCurrency(value: unknown): value is string {
    return typeof value === 'string' && CURRENCY.test(value);
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

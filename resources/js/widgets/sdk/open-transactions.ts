import { CASHFLOW_BLOCKS } from '@/lib/cashflow-block';
import { index as transactionsIndex } from '@/routes/transactions';
import { CATEGORY_TYPES, type CategoryType } from '@/types/category';
import { type CashflowBlock } from '@/types/transaction';
import { currencyDecimals, toMajorUnits } from '@/utils/currency';
import { isDate, isPlainObject, isUuid, type Resolved } from './open-values';

/**
 * The filters `wm.open({ transactions })` takes: the ones the transactions
 * page takes, each optional and combinable. The page shows each one as an
 * active filter, as if the user had picked it. Categories and labels widen
 * each other (a row in one of the categories OR with one of the labels), as
 * they do on the page; every other filter narrows the list.
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

type QueryValue = string | string[];

type Query = Record<string, QueryValue>;

type FilterParam =
    | { name: string; value: QueryValue | null }
    | { error: string };

/** Reads one filter's value, given every filter for the ones that pair up. */
type Parser = (
    name: string,
    value: unknown,
    filters: Record<string, unknown>,
) => FilterParam;

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

/**
 * Each filter beside how it becomes its query parameter. Keyed by the
 * interface, so a filter it declares cannot go without a parser.
 */
const PARSERS: Record<keyof OpenTransactionsFilters, Parser> = {
    categoryIds: idList(
        'category_ids',
        (id) => isUuid(id) || id === UNCATEGORIZED,
    ),
    categoryTypes: oneOfList('category_types', CATEGORY_TYPES),
    accountIds: idList('account_ids', isUuid),
    labelIds: idList('label_ids', isUuid),
    dateFrom: date('date_from'),
    dateTo: date('date_to'),
    cashflowBlock: oneOf('cashflow_block', CASHFLOW_BLOCKS),
    amountMin: amount('amount_min'),
    amountMax: amount('amount_max'),
    currency: currency,
    creditorName: text('creditor_name', 255),
    debtorName: text('debtor_name', 255),
    categorySource: oneOf('category_source', CATEGORY_SOURCES),
    search: text('search', 200),
};

const FILTER_NAMES = Object.keys(PARSERS).join(', ');

/** How `wm.open()`'s usage line names this destination. */
export const TRANSACTIONS_USAGE = `{ transactions: { ${FILTER_NAMES} } }`;

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

/** The transactions list with those filters, or why they are not filters. */
export function transactionsUrl(filters: unknown): Resolved {
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
    if (!Object.hasOwn(PARSERS, name)) {
        return {
            error: `wm.open({ transactions }) has no "${name}" filter; it takes ${FILTER_NAMES}.`,
        };
    }

    return value === undefined
        ? { name, value: null }
        : PARSERS[name as keyof OpenTransactionsFilters](name, value, filters);
}

function refuse(name: string, expectation: string): FilterParam {
    return { error: `wm.open({ transactions: { ${name} } }) ${expectation}` };
}

function idList(param: string, isId: (id: unknown) => boolean): Parser {
    return (name, value) =>
        Array.isArray(value) && value.every(isId)
            ? { name: param, value: value.length > 0 ? value : null }
            : refuse(name, 'takes a list of ids, as the API answers them.');
}

function oneOfList(param: string, values: readonly string[]): Parser {
    return (name, value) =>
        Array.isArray(value) && value.every((item) => isOneOf(item, values))
            ? { name: param, value: value.length > 0 ? value : null }
            : refuse(name, `takes a list of ${quoted(values)}.`);
}

function oneOf(param: string, values: readonly string[]): Parser {
    return (name, value) =>
        isOneOf(value, values)
            ? { name: param, value }
            : refuse(name, `takes one of ${quoted(values)}.`);
}

function date(param: string): Parser {
    return (name, value) =>
        isDate(value)
            ? { name: param, value }
            : refuse(name, 'takes a date written YYYY-MM-DD.');
}

function text(param: string, maxLength: number): Parser {
    return (name, value) =>
        typeof value === 'string' && value.length <= maxLength
            ? { name: param, value: value.trim() === '' ? null : value }
            : refuse(name, `takes text of up to ${maxLength} characters.`);
}

/**
 * The list's amount filter reads major units (`12.34`), each transaction in
 * its own currency, while a widget holds minor units (`1234`): the currency
 * the widget names says how many decimals the figure has.
 */
function amount(param: string): Parser {
    return (name, value, filters) => {
        if (!Number.isSafeInteger(value)) {
            return refuse(
                name,
                "takes a whole number of minor units (cents for EUR), signed like the API's amounts: expenses are negative.",
            );
        }

        if (!isCurrency(filters.currency)) {
            return refuse(
                name,
                `needs the currency its minor units are in, e.g. { ${name}: ${String(value)}, currency: 'EUR' }.`,
            );
        }

        const code = filters.currency.toUpperCase();

        return {
            name: param,
            value: toMajorUnits(value as number, code).toFixed(
                currencyDecimals(code),
            ),
        };
    };
}

/** The currency only scales the amounts: it is no filter of its own. */
function currency(name: string, value: unknown): FilterParam {
    return isCurrency(value)
        ? { name, value: null }
        : refuse(name, 'takes a currency code, e.g. EUR.');
}

function quoted(values: readonly string[]): string {
    return values.map((value) => `"${value}"`).join(', ');
}

function isOneOf(value: unknown, values: readonly string[]): value is string {
    return typeof value === 'string' && values.includes(value);
}

function isOrdered(
    from: QueryValue | undefined,
    to: QueryValue | undefined,
): boolean {
    return typeof from !== 'string' || typeof to !== 'string' || from <= to;
}

function isCurrency(value: unknown): value is string {
    return typeof value === 'string' && CURRENCY.test(value);
}

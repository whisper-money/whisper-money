import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import {
    type CategorySource,
    type DecryptedTransaction,
    type TransactionSource,
    type TransactionSplit,
} from '@/types/transaction';
import { safeLogoUrl } from './bank-logo';
import { type WidgetAccount } from './context';

/**
 * The rows of `GET /transactions` as the SDK reads them, for the widget's own
 * read-only list and for the host's table, which rebuilds every row from its
 * own data with these same functions. Widget code may have run by the time
 * they are called, so the statics they use are taken here, when the module
 * loads, before any widget can.
 */
const { isArray } = Array;
const { hasOwn } = Object;
const { isFinite: isFiniteNumber } = Number;

export function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

interface NamedRef {
    id: string;
    name: string;
}

/** A line of a split transaction, as `GET /transactions` nests it. */
interface ApiSplitLine {
    id: string;
    category: NamedRef | null;
    amount: number;
    position: number;
}

/**
 * A row of `GET /transactions` (TransactionResource), the fields read here. A
 * part of a split carries the `split_parent_id` its siblings share; a
 * transaction still split the old way is one row with its lines under
 * `splits`; a field still encrypted on the device comes as ''.
 */
export interface ApiTransaction {
    /** The row as the API answered it, which the formatters receive. */
    raw: Readonly<Record<string, unknown>>;
    id: string;
    transaction_date: string;
    description: string;
    amount: number;
    currency: string;
    account: NamedRef | null;
    category: NamedRef | null;
    category_source: CategorySource | null;
    labels: NamedRef[];
    source: TransactionSource;
    notes: string | null;
    creditor_name: string | null;
    debtor_name: string | null;
    split_parent_id: string | null;
    splits: ApiSplitLine[];
}

function stringOrNull(value: unknown): string | null {
    return typeof value === 'string' ? value : null;
}

function namedRef(value: unknown): NamedRef | null {
    return isRecord(value) &&
        typeof value.id === 'string' &&
        typeof value.name === 'string'
        ? { id: value.id, name: value.name }
        : null;
}

function splitLine(value: unknown): ApiSplitLine | null {
    return isRecord(value) &&
        typeof value.id === 'string' &&
        typeof value.amount === 'number' &&
        isFiniteNumber(value.amount)
        ? {
              id: value.id,
              category: namedRef(value.category),
              amount: value.amount,
              position: typeof value.position === 'number' ? value.position : 0,
          }
        : null;
}

/**
 * One API row, or null when it lacks what a line needs (an id, a date, an
 * amount and its currency): widget data is whatever the widget handed over.
 */
export function apiTransaction(value: unknown): ApiTransaction | null {
    if (
        !isRecord(value) ||
        typeof value.id !== 'string' ||
        typeof value.transaction_date !== 'string' ||
        typeof value.amount !== 'number' ||
        !isFiniteNumber(value.amount) ||
        typeof value.currency !== 'string'
    ) {
        return null;
    }

    return {
        raw: value,
        id: value.id,
        transaction_date: value.transaction_date,
        description:
            typeof value.description === 'string' ? value.description : '',
        amount: value.amount,
        currency: value.currency,
        account: namedRef(value.account),
        category: namedRef(value.category),
        category_source: (value.category_source ??
            null) as CategorySource | null,
        labels: isArray(value.labels)
            ? value.labels.map(namedRef).filter((label) => label !== null)
            : [],
        source: value.source as TransactionSource,
        notes: stringOrNull(value.notes),
        creditor_name: stringOrNull(value.creditor_name),
        debtor_name: stringOrNull(value.debtor_name),
        split_parent_id: stringOrNull(value.split_parent_id),
        splits: isArray(value.splits)
            ? value.splits.map(splitLine).filter((line) => line !== null)
            : [],
    };
}

/** The rows of `transactions`: a list, or a `paginate: "all"` answer. */
export function apiTransactions(value: unknown): ApiTransaction[] {
    const rows = isRecord(value) && !isArray(value) ? value.data : value;

    return isArray(rows)
        ? rows.map(apiTransaction).filter((row) => row !== null)
        : [];
}

function bankOf(account: WidgetAccount | undefined): Bank | undefined {
    return account?.bankName
        ? {
              id: '',
              user_id: null,
              name: account.bankName,
              logo: safeLogoUrl(account.bankLogo),
          }
        : undefined;
}

/** A split's lines as the app's table draws them, each with its category. */
function splitsOf(
    row: ApiTransaction,
    categoryById: ReadonlyMap<string, Category>,
): TransactionSplit[] {
    return row.splits.map((line) => ({
        id: line.id,
        transaction_id: row.id,
        category_id: line.category?.id ?? '',
        category: line.category
            ? (categoryById.get(line.category.id) ?? null)
            : null,
        amount: line.amount,
        position: line.position,
    }));
}

/**
 * An API row as the shape the app's own table holds its rows in: a part of a
 * split keeps its `split_parent_id` (its siblings come with the row a dialog
 * opens on), a transaction split the old way stays one row, at its own amount,
 * with its lines. Notes the server could not read ('') are left out.
 */
export function toHeldTransaction(
    row: ApiTransaction,
    accounts: Readonly<Record<string, WidgetAccount>>,
    categoryById: ReadonlyMap<string, Category>,
): DecryptedTransaction {
    const notes = row.notes === '' ? null : row.notes;
    const splits = splitsOf(row, categoryById);

    return {
        id: row.id,
        user_id: '',
        account_id: row.account?.id ?? '',
        category_id: row.category?.id ?? null,
        description: row.description,
        description_iv: null,
        decryptedDescription: row.description,
        transaction_date: row.transaction_date,
        amount: row.amount,
        currency_code: row.currency,
        notes,
        notes_iv: null,
        decryptedNotes: notes,
        source: row.source,
        category_source: row.category_source,
        label_ids: row.labels.map((label) => label.id),
        creditor_name: row.creditor_name,
        debtor_name: row.debtor_name,
        split_parent_id: row.split_parent_id,
        splits,
        is_split: splits.length > 0,
        split_count: splits.length,
        created_at: '',
        updated_at: '',
        // The rows read only the name, the bank its logo and name.
        account: (row.account ?? undefined) as Account | undefined,
        bank:
            row.account && hasOwn(accounts, row.account.id)
                ? bankOf(accounts[row.account.id])
                : undefined,
    };
}

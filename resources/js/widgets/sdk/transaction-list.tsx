import {
    DayBand,
    DayTotal,
} from '@/components/transactions/transaction-day-header';
import { TransactionListRow } from '@/components/transactions/transaction-list-row';
import { Button as AppButton } from '@/components/ui/button';
import { FixedPrivacyMode } from '@/contexts/privacy-mode-context';
import {
    groupByDate,
    useInMemoryDaySummaries,
} from '@/hooks/use-day-summaries';
import { compareNewestFirst } from '@/hooks/use-in-memory-transaction-source';
import { cn } from '@/lib/utils';
import { type Account, type Bank } from '@/types/account';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import {
    type CategorySource,
    type ServerTransaction,
    type TransactionSource,
} from '@/types/transaction';
import { __ } from '@/utils/i18n';
import { Fragment, useMemo, useState } from 'react';
import { safeLogoUrl } from './bank-logo';
import { useWidgetSdk, type WidgetAccount } from './context';
import { EmptyState } from './primitives';
import { classNameOf } from './props';

/** The parts of a row a widget may leave out with `hide`. */
const HIDEABLE_PARTS = ['account', 'labels', 'notes'] as const;

type HideablePart = (typeof HIDEABLE_PARTS)[number];

interface NamedRef {
    id: string;
    name: string;
}

/** A row of `GET /transactions` (TransactionResource), the fields read here. */
interface ApiTransaction {
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
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null;
}

function namedRef(value: unknown): NamedRef | null {
    return isRecord(value) &&
        typeof value.id === 'string' &&
        typeof value.name === 'string'
        ? { id: value.id, name: value.name }
        : null;
}

/**
 * One API row, or null when it lacks what a line needs (an id, a date, an
 * amount and its currency): widget data is whatever the widget handed over.
 */
function apiTransaction(value: unknown): ApiTransaction | null {
    if (
        !isRecord(value) ||
        typeof value.id !== 'string' ||
        typeof value.transaction_date !== 'string' ||
        typeof value.amount !== 'number' ||
        !Number.isFinite(value.amount) ||
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
        labels: Array.isArray(value.labels)
            ? value.labels.map(namedRef).filter((label) => label !== null)
            : [],
        source: value.source as TransactionSource,
        notes: typeof value.notes === 'string' ? value.notes : null,
    };
}

/** The rows of `transactions`: a list, or a `paginate: "all"` answer. */
function apiTransactions(value: unknown): ApiTransaction[] {
    const rows = isRecord(value) && !Array.isArray(value) ? value.data : value;

    return Array.isArray(rows)
        ? rows.map(apiTransaction).filter((row) => row !== null)
        : [];
}

/**
 * The parts `hide` names, as a string: a widget writes `hide={['notes']}`
 * inline, a new array on every render, and the rows should not be rebuilt
 * for it.
 */
function hiddenPartsKey(value: unknown): string {
    return Array.isArray(value)
        ? HIDEABLE_PARTS.filter((part) => value.includes(part)).join()
        : '';
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

/** An API row as the shape the app's own list draws. */
function toServerTransaction(
    row: ApiTransaction,
    accounts: Readonly<Record<string, WidgetAccount>>,
    hidden: ReadonlySet<HideablePart>,
): ServerTransaction {
    const account =
        row.account && !hidden.has('account') ? row.account : undefined;

    return {
        id: row.id,
        user_id: '',
        account_id: row.account?.id ?? '',
        category_id: row.category?.id ?? null,
        description: row.description,
        transaction_date: row.transaction_date,
        amount: row.amount,
        currency_code: row.currency,
        notes: hidden.has('notes') ? null : row.notes,
        source: row.source,
        category_source: row.category_source,
        label_ids: hidden.has('labels')
            ? []
            : row.labels.map((label) => label.id),
        created_at: '',
        updated_at: '',
        // The row reads only the name, the bank its logo and name.
        account: account as Account | undefined,
        bank:
            account && Object.hasOwn(accounts, account.id)
                ? bankOf(accounts[account.id])
                : undefined,
    };
}

/**
 * The user's categories, plus one for each category a row names that the
 * page does not know (another member's in a shared space), so the row says
 * its name rather than "Uncategorized".
 */
function categoriesFor(
    rows: ApiTransaction[],
    known: readonly Category[],
): Category[] {
    const byId = new Map(known.map((category) => [category.id, category]));

    for (const { category } of rows) {
        if (category && !byId.has(category.id)) {
            byId.set(category.id, {
                id: category.id,
                name: category.name,
                icon: 'HelpCircle',
                color: 'gray',
                type: 'expense',
                cashflow_direction: 'outflow',
                parent_id: null,
            } as Category);
        }
    }

    return [...byId.values()];
}

/** The user's labels, plus the ones rows carry that the page does not know. */
function labelsFor(rows: ApiTransaction[], known: readonly Label[]): Label[] {
    const byId = new Map(known.map((label) => [label.id, label]));

    for (const label of rows.flatMap((row) => row.labels)) {
        if (!byId.has(label.id)) {
            byId.set(label.id, { ...label, color: 'gray' } as Label);
        }
    }

    return [...byId.values()];
}

/** What a formatter said about a row, kept only when it is a string. */
function formatted(
    formatter: unknown,
    row: ApiTransaction | undefined,
): string | null {
    if (typeof formatter !== 'function' || row === undefined) {
        return null;
    }

    const value: unknown = formatter(row.raw);

    return typeof value === 'string' ? value : null;
}

function positiveInteger(value: unknown): number | null {
    return typeof value === 'number' && Number.isFinite(value) && value >= 1
        ? Math.trunc(value)
        : null;
}

/**
 * The transactions of a `GET /transactions` answer drawn as the app's own
 * compact list (the one at the bottom of an account page): newest first,
 * under a band per day with the day's total, each row with its category
 * icon, account, labels and notes. Read-only: a row opens nothing yet.
 *
 * - `transactions`: the rows, or the `{ data, truncated }` of `paginate: "all"`.
 * - `hide`: parts to leave out of every row: `'account'`, `'labels'`, `'notes'`.
 * - `limit`: rows drawn at first; a "Show more" button adds as many again.
 * - `description(row)`: the text a row shows in place of its description.
 * - `badge(row)`: a short tag after the description, or null for none.
 * - `hiddenLabelId`: a label every row carries, left out of each.
 * - `showDayTotals`: false leaves the day bands without their total.
 * - `emptyTitle` / `emptyDescription`: what an empty list says.
 */
export function TransactionList({
    transactions,
    hide,
    limit,
    description,
    badge,
    hiddenLabelId,
    showDayTotals = true,
    emptyTitle,
    emptyDescription,
    className,
}: {
    transactions: unknown;
    hide?: unknown;
    limit?: unknown;
    description?: unknown;
    badge?: unknown;
    hiddenLabelId?: unknown;
    showDayTotals?: unknown;
    emptyTitle?: unknown;
    emptyDescription?: unknown;
    className?: unknown;
}) {
    const sdk = useWidgetSdk();
    const pageSize = positiveInteger(limit);
    // Pages, not rows, so a limit the widget changes later still applies.
    const [pages, setPages] = useState(1);
    const shown = pageSize === null ? Infinity : pageSize * pages;
    const hiddenKey = hiddenPartsKey(hide);

    const rows = useMemo(() => apiTransactions(transactions), [transactions]);
    const rowById = useMemo(
        () => new Map(rows.map((row) => [row.id, row])),
        [rows],
    );
    const listed = useMemo(() => {
        const hidden = new Set(hiddenKey.split(',') as HideablePart[]);

        return rows
            .map((row) => toServerTransaction(row, sdk.accounts, hidden))
            .sort(compareNewestFirst);
    }, [rows, sdk.accounts, hiddenKey]);
    const daySummaryOf = useInMemoryDaySummaries(listed);
    const categories = useMemo(
        () => categoriesFor(rows, sdk.categories),
        [rows, sdk.categories],
    );
    const labels = useMemo(
        () => labelsFor(rows, sdk.labels),
        [rows, sdk.labels],
    );

    if (listed.length === 0) {
        return (
            <EmptyState
                className={className}
                title={
                    typeof emptyTitle === 'string'
                        ? emptyTitle
                        : __('No transactions')
                }
                description={emptyDescription}
            />
        );
    }

    const visible = listed.slice(0, shown);
    const hasMore = visible.length < listed.length;
    const lastId = visible.at(-1)?.id;

    return (
        <FixedPrivacyMode enabled={sdk.env.privacyMode}>
            <div
                data-slot="widget-transaction-list"
                className={cn('flex flex-col', classNameOf(className))}
            >
                {[...groupByDate(visible)].map(([date, day]) => (
                    <Fragment key={date}>
                        <DayBand date={date}>
                            {showDayTotals !== false && (
                                <DayTotal
                                    total={daySummaryOf(date).total}
                                    quiet
                                />
                            )}
                        </DayBand>
                        {day.map((transaction) => {
                            const row = rowById.get(transaction.id);

                            // A copy for display only (hidden parts blanked,
                            // the description rewritten): a row that opens the
                            // edit dialog must open it by id, not this copy.

                            return (
                                <TransactionListRow
                                    key={transaction.id}
                                    transaction={{
                                        ...transaction,
                                        description:
                                            formatted(description, row) ??
                                            transaction.description,
                                    }}
                                    categories={categories}
                                    labels={labels}
                                    selected={false}
                                    selectionActive={false}
                                    onUnsplit={() => undefined}
                                    badge={formatted(badge, row) ?? undefined}
                                    hiddenLabelId={
                                        typeof hiddenLabelId === 'string'
                                            ? hiddenLabelId
                                            : undefined
                                    }
                                    // The widget's card draws the closing hairline.
                                    className={cn(
                                        !hasMore &&
                                            transaction.id === lastId &&
                                            'border-b-0',
                                    )}
                                />
                            );
                        })}
                    </Fragment>
                ))}
                {hasMore && pageSize !== null && (
                    <AppButton
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="m-2"
                        onClick={() => setPages((count) => count + 1)}
                    >
                        {__('Show more')}
                    </AppButton>
                )}
            </div>
        </FixedPrivacyMode>
    );
}

import { FORBIDDEN_PROPERTY_NAMES } from './allowlist';
import type { ComponentProps } from './component-props';

/**
 * The contract of `@whisper-money/widget-sdk`, as the validator enforces it.
 * The SDK itself is built in T8, which must export exactly these names with
 * these props: a widget that passes validation imports nothing else.
 *
 * Values (hooks and helpers):
 * - `useWidgetData()`: the data the manifest declared, keyed by its names.
 * - `useWidgetEnv()`: `{ theme, width, height, isCompact, privacyMode, currency, locale }`.
 * - `formatMoney(minorUnits, currency, options?)`: amounts arrive in minor units.
 * - `get(obj, key)`: computed-key access; only own properties, GET_FORBIDDEN_KEYS rejected.
 * - `cn(...classes)`: joins literal classes (the validator reads its arguments).
 * - `wm`: `{ download(filename, content) }`, the only way to hand data to the user.
 */
export const SDK_VALUES: ReadonlySet<string> = new Set([
    'useWidgetData',
    'useWidgetEnv',
    'formatMoney',
    'get',
    'cn',
    'wm',
]);

/**
 * Keys `get(obj, key)` must reject at runtime (and the validator rejects when
 * the key is a literal): every forbidden property name except `target`. Widgets
 * never receive events (handlers take no parameters, Recharts handlers are not
 * allowed) and `get` only reads own properties, so `get(obj, 'target')` is the
 * documented way to read data fields called `target`, like
 * SavingsGoalResource's `progress.target`. Dot and bracket access stay
 * forbidden for all of them.
 */
export const GET_FORBIDDEN_KEYS: ReadonlySet<string> = new Set(
    [...FORBIDDEN_PROPERTY_NAMES].filter((name) => name !== 'target'),
);

const KEY_AND_CLASS = { key: 'value', className: 'className' } as const;

/**
 * SDK components and their closed list of props. UI primitives carry our
 * design tokens for light and dark mode.
 */
export const SDK_COMPONENTS: Readonly<Record<string, ComponentProps>> = {
    /** An amount in minor units, blurred while privacy mode is on. */
    Amount: {
        ...KEY_AND_CLASS,
        amount: 'value',
        currency: 'value',
        showSign: 'value',
        compact: 'value',
    },
    /** The bank logo of an account, falling back to the account type icon. */
    BankLogo: { ...KEY_AND_CLASS, accountId: 'value', size: 'value' },
    /** Recharts tooltip with our styling; replaces `<Tooltip content>`. */
    ChartTooltip: {
        key: 'value',
        valueFormatter: 'formatter',
        labelFormatter: 'formatter',
        hideLabel: 'value',
        indicator: 'value',
        cursor: 'boolean',
    },
    Card: KEY_AND_CLASS,
    CardHeader: KEY_AND_CLASS,
    CardTitle: KEY_AND_CLASS,
    CardDescription: KEY_AND_CLASS,
    CardContent: KEY_AND_CLASS,
    CardFooter: KEY_AND_CLASS,
    Badge: { ...KEY_AND_CLASS, variant: 'value' },
    Button: {
        ...KEY_AND_CLASS,
        variant: 'value',
        size: 'value',
        disabled: 'value',
        onClick: 'handler',
    },
    Table: KEY_AND_CLASS,
    TableHeader: KEY_AND_CLASS,
    TableBody: KEY_AND_CLASS,
    TableFooter: KEY_AND_CLASS,
    TableRow: KEY_AND_CLASS,
    TableHead: { ...KEY_AND_CLASS, colSpan: 'value' },
    TableCell: { ...KEY_AND_CLASS, colSpan: 'value' },
    Progress: { ...KEY_AND_CLASS, value: 'value' },
    Separator: { ...KEY_AND_CLASS, orientation: 'value' },
    Skeleton: KEY_AND_CLASS,
    EmptyState: { ...KEY_AND_CLASS, title: 'text', description: 'text' },
    /**
     * The app's own transaction list (newest first, a band per day with its
     * total), drawn from the rows of `GET /transactions`. Its `description`
     * and `badge` formatters receive one row and return a string.
     */
    TransactionList: {
        ...KEY_AND_CLASS,
        transactions: 'value',
        hide: 'value',
        limit: 'value',
        description: 'formatter',
        badge: 'formatter',
        hiddenLabelId: 'value',
        showDayTotals: 'value',
        emptyTitle: 'text',
        emptyDescription: 'text',
    },
};

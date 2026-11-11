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
 * - `get(obj, key)`: computed-key access; only own properties, forbidden keys rejected.
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
};

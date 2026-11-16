import { cn as joinClasses } from '@/lib/utils';
import { download } from './download';

/**
 * `@whisper-money/widget-sdk`: everything a user widget may import besides
 * React, Recharts and lucide. The validator holds the contract
 * (validator/sdk-spec.ts) and sdk.test.ts checks this module exports exactly
 * that, no more and no less.
 */
export { Amount } from './amount';
export { BankLogo } from './bank-logo';
export { ChartTooltip } from './chart-tooltip';
export { useWidgetData, useWidgetEnv } from './context';
export { formatMoney } from './format-money';
export { get } from './get';
export {
    Badge,
    Button,
    Card,
    CardContent,
    CardDescription,
    CardFooter,
    CardHeader,
    CardTitle,
    EmptyState,
    Progress,
    Separator,
    Skeleton,
    Table,
    TableBody,
    TableCell,
    TableFooter,
    TableHead,
    TableHeader,
    TableRow,
} from './primitives';
export { TransactionList } from './transaction-list';

/** Joins class names; the validator reads its literal arguments. */
export function cn(...classes: unknown[]): string {
    return joinClasses(
        ...classes.filter(
            (value): value is string | false | null | undefined =>
                typeof value === 'string' ||
                value === false ||
                value === null ||
                value === undefined,
        ),
    );
}

/** The widget's way out to the user: `wm.download(filename, content)`. */
export const wm = Object.freeze({ download });

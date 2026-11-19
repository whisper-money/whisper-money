import { type WidgetSize } from '@/components/dashboard/widgets/types';
import { type WidgetFrame } from '@/types/dashboard';
import { lazy, Suspense } from 'react';
import { type OpenWidgetDialog } from '../sdk/gesture';
import { type RenderWidgetTransactionTable } from '../sdk/transaction-table-host';
import { type WidgetRuntimeError } from './widget-error-boundary';

export interface UserWidgetProps {
    /** The compiled module, as the compiler stored it. */
    js: string;
    /** The data its manifest declared, already resolved by the gateway. */
    data: Readonly<Record<string, unknown>>;
    size: WidgetSize;
    /** What the dashboard draws around it once it renders; a card by default. */
    frame?: WidgetFrame;
    /** A load or render failure, to be stored with the widget. */
    onError?: (error: WidgetRuntimeError) => void;
    /** The widget has drawn, or failed: safe mode counts these. */
    onSettled?: () => void;
    /**
     * Draws the SDK's `<TransactionList>` as the app's own transactions
     * table, editing and all; without it its rows are only read. Only rows of
     * the widget's own data reach it, and never from widget code.
     */
    renderTransactionTable?: RenderWidgetTransactionTable;
    /**
     * Lets the widget's `wm.open()` take the user to another page. Off by
     * default: a dashboard being edited leaves it off.
     */
    canNavigate?: boolean;
    /**
     * Opens the app's new-transaction dialog or importer over the page for
     * the widget's `wm.open()`. Absent where the host has none (the MCP
     * preview); like navigation, it needs `canNavigate`.
     */
    openDialog?: OpenWidgetDialog;
    className?: string;
}

const UserWidgetFrame = lazy(() => import('./user-widget-frame'));

/**
 * A widget written by the user's agent, drawn in its dashboard cell. It loads
 * the compiled module on the app's own React (through the T7 loader), mounts
 * it inside `.wm-widget` (the scope of the precompiled widget CSS) and an
 * error boundary, and hands it data, theme, size, privacy mode and currency
 * through the SDK context (see user-widget-frame.tsx).
 */
export function UserWidget(props: UserWidgetProps) {
    return (
        <Suspense
            // Plain markup on purpose: a component imported here would end up
            // in the page's chunk, and the lazy chunks importing it from there
            // cost the page its entry in Vite's manifest.
            fallback={
                <div
                    aria-busy="true"
                    className="h-full w-full animate-pulse rounded-xl border bg-primary/10"
                />
            }
        >
            {/* A new module is a new widget: its error and load state start over. */}
            <UserWidgetFrame key={props.js} {...props} />
        </Suspense>
    );
}

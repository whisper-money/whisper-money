import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { type UserWidgetDefinition } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { type UserWidgetsGate } from '@/widgets/runtime/use-user-widgets-gate';
import { UserWidget } from '@/widgets/runtime/user-widget';
import {
    WidgetErrorState,
    type WidgetRuntimeError,
} from '@/widgets/runtime/widget-error-boundary';
import { EllipsisVertical, ShieldAlert, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { type PlacedUserWidget } from './grid-layout';
import {
    resolveWidgetData,
    type ResolvedWidgetData,
    type UserWidgetDataState,
} from './use-user-widget-data';
import {
    currentWidgetError,
    type CaughtWidgetError,
} from './use-widget-error-reporter';
import { UserWidgetDetailsDialog } from './user-widget-details-dialog';

export interface UserWidgetCellProps {
    placement: PlacedUserWidget;
    /** Undefined until the page's deferred user widgets land. */
    definition: UserWidgetDefinition | undefined;
    gate: UserWidgetsGate;
    data: UserWidgetDataState;
    caughtError: CaughtWidgetError | undefined;
    onError: (
        definition: UserWidgetDefinition,
        error: WidgetRuntimeError,
    ) => void;
    canRevert: boolean;
    /** The dashboard is being edited: its menu is only offered then, or on an error. */
    isEditing: boolean;
    /** Takes it off the dashboard; absent when the user cannot edit it. */
    onRemove?: () => void;
}

type Content =
    | { kind: 'off' }
    | { kind: 'loading' }
    | { kind: 'safe' }
    | { kind: 'data-failed'; message: string }
    | { kind: 'ready'; resolved: ResolvedWidgetData };

function useCellContent(
    placement: PlacedUserWidget,
    definition: UserWidgetDefinition | undefined,
    gate: UserWidgetsGate,
    data: UserWidgetDataState,
): Content {
    const answers =
        data.status === 'ready'
            ? data.widgets[placement.definitionId]?.data
            : undefined;
    const resolved = useMemo(
        () => (answers === undefined ? null : resolveWidgetData(answers)),
        [answers],
    );

    if (gate.mode === 'off') {
        return { kind: 'off' };
    }

    if (gate.mode === 'safe') {
        return { kind: 'safe' };
    }

    if (definition === undefined || gate.mode !== 'run') {
        return { kind: 'loading' };
    }

    // A widget just added has no data until the new request lands.
    if (
        data.status === 'loading' ||
        (resolved === null && data.status === 'ready' && data.refreshing)
    ) {
        return { kind: 'loading' };
    }

    if (resolved === null) {
        return {
            kind: 'data-failed',
            message: __('The data request did not go through.'),
        };
    }

    const [failure] = resolved.failures;

    if (failure !== undefined) {
        return { kind: 'data-failed', message: failure.message };
    }

    return { kind: 'ready', resolved };
}

/**
 * A widget the user's agent wrote, in its dashboard cell: a skeleton while its
 * module and data load, the widget once they have, and its failures (data the
 * API refused, code that throws) kept to the cell. Its menu opens the
 * widget's details: the last error and the versions to go back to. It is
 * offered while the dashboard is edited, and outside it only on an error.
 */
export function UserWidgetCell({
    placement,
    definition,
    gate,
    data,
    caughtError,
    onError,
    canRevert,
    isEditing,
    onRemove,
}: UserWidgetCellProps) {
    const content = useCellContent(placement, definition, gate, data);
    const [detailsOpen, setDetailsOpen] = useState(false);
    const settle = gate.mode === 'run' ? gate.settle : undefined;
    const error =
        definition === undefined
            ? null
            : currentWidgetError(definition, caughtError);

    // A cell that will never mount its widget is as settled as it gets.
    useEffect(() => {
        if (content.kind === 'data-failed') {
            settle?.(placement.id);
        }
    }, [content.kind, settle, placement.id]);

    if (content.kind === 'off') {
        return null;
    }

    return (
        <div
            data-user-widget={placement.definitionId}
            data-state={content.kind}
            className="group/user-widget relative h-full min-h-0"
        >
            {content.kind === 'loading' && (
                <Skeleton
                    aria-busy="true"
                    aria-label={__('Loading widget')}
                    className="h-full w-full rounded-xl"
                />
            )}
            {content.kind === 'safe' && (
                <SafeModePlaceholder
                    name={definition?.name}
                    onRemove={onRemove}
                />
            )}
            {content.kind === 'data-failed' && (
                <WidgetErrorState
                    title={__('This widget could not get its data')}
                    hint={__(
                        'Ask your agent to check what it reads, or try again later.',
                    )}
                    message={content.message}
                />
            )}
            {content.kind === 'ready' && definition !== undefined && (
                <>
                    <UserWidget
                        js={definition.js}
                        data={content.resolved.data}
                        size={placement.size}
                        frame={definition.manifest.frame}
                        onError={(caught) => onError(definition, caught)}
                        onSettled={() => settle?.(placement.id)}
                    />
                    {content.resolved.truncated && (
                        <span
                            data-slot="user-widget-truncated"
                            title={__(
                                'There was too much to read at once: this widget only got part of the data.',
                            )}
                            className="pointer-events-none absolute bottom-2 left-2 rounded-md border bg-background/90 px-1.5 py-0.5 text-xs text-muted-foreground"
                        >
                            {__('Partial data')}
                        </span>
                    )}
                </>
            )}

            {definition !== undefined &&
                content.kind !== 'safe' &&
                (isEditing || error !== null) && (
                    <>
                        <UserWidgetMenu
                            name={definition.name}
                            hasError={error !== null}
                            onDetails={() => setDetailsOpen(true)}
                            onRemove={onRemove}
                        />
                        <UserWidgetDetailsDialog
                            definition={definition}
                            error={error}
                            open={detailsOpen}
                            onOpenChange={setDetailsOpen}
                            canRevert={canRevert}
                        />
                    </>
                )}
        </div>
    );
}

function UserWidgetMenu({
    name,
    hasError,
    onDetails,
    onRemove,
}: {
    name: string;
    hasError: boolean;
    onDetails: () => void;
    onRemove?: () => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="outline"
                    size="icon-sm"
                    aria-label={__('Options for :widget', { widget: name })}
                    className={cn(
                        'absolute top-2 right-2 bg-background/90',
                        // A widget drawn without its card has no inset to
                        // sit in: take its corner instead.
                        'group-has-[[data-slot=user-widget][data-frame=none]]/user-widget:top-0 group-has-[[data-slot=user-widget][data-frame=none]]/user-widget:right-0',
                    )}
                >
                    <EllipsisVertical className="size-4" />
                    {hasError && (
                        <span
                            aria-hidden
                            className="absolute -top-0.5 -right-0.5 size-2 rounded-full bg-destructive dark:bg-red-400"
                        />
                    )}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onDetails}>
                    {hasError
                        ? __('See the error and versions')
                        : __('Versions')}
                </DropdownMenuItem>
                {onRemove && (
                    <DropdownMenuItem variant="destructive" onSelect={onRemove}>
                        {__('Remove from dashboard')}
                    </DropdownMenuItem>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

/** A user widget left off in safe mode, which can still be taken off the dashboard. */
function SafeModePlaceholder({
    name,
    onRemove,
}: {
    name: string | undefined;
    onRemove?: () => void;
}) {
    return (
        <div className="flex h-full flex-col items-center justify-center gap-2 rounded-xl border border-dashed p-4 text-center">
            <ShieldAlert className="size-5 text-muted-foreground" />
            {name !== undefined && (
                <p className="max-w-full truncate text-sm font-medium">
                    {name}
                </p>
            )}
            <p className="text-xs text-muted-foreground">
                {__('Not loaded in safe mode.')}
            </p>
            {onRemove && (
                <Button variant="outline" size="sm" onClick={onRemove}>
                    <X className="size-4" />
                    {__('Remove from dashboard')}
                </Button>
            )}
        </div>
    );
}

import { Badge as AppBadge } from '@/components/ui/badge';
import { Button as AppButton } from '@/components/ui/button';
import * as AppCard from '@/components/ui/card';
import { Progress as AppProgress } from '@/components/ui/progress';
import { Separator as AppSeparator } from '@/components/ui/separator';
import { Skeleton as AppSkeleton } from '@/components/ui/skeleton';
import * as AppTable from '@/components/ui/table';
import { cn } from '@/lib/utils';
import { type ComponentType, type ReactNode, useState } from 'react';
import { classNameOf, finiteNumber, oneOf } from './props';

/**
 * The UI primitives widgets get: the app's own components, so they carry its
 * tokens for light and dark mode, each behind a wrapper that forwards only
 * the props the validator allows for it (the closed lists in sdk-spec.ts).
 * Nothing else a widget passes reaches the app component: no `asChild`, no
 * `ref`, no stray handler.
 */

interface ClassAndChildren {
    className?: unknown;
    children?: ReactNode;
}

type WithSpan = { colSpan?: unknown };

/** A primitive that takes nothing but a class and its children. */
function classOnly(
    Component: ComponentType<{ className?: string; children?: ReactNode }>,
    displayName: string,
): ComponentType<ClassAndChildren> {
    const Primitive = ({ className, children }: ClassAndChildren) => (
        <Component className={classNameOf(className)}>{children}</Component>
    );

    Primitive.displayName = displayName;

    return Primitive;
}

/** A table cell, which also takes `colSpan`. */
function spanning(
    Component: ComponentType<{
        className?: string;
        colSpan?: number;
        children?: ReactNode;
    }>,
    displayName: string,
): ComponentType<ClassAndChildren & WithSpan> {
    const Primitive = ({
        className,
        colSpan,
        children,
    }: ClassAndChildren & WithSpan) => (
        <Component
            className={classNameOf(className)}
            colSpan={
                typeof colSpan === 'number' ? Math.trunc(colSpan) : undefined
            }
        >
            {children}
        </Component>
    );

    Primitive.displayName = displayName;

    return Primitive;
}

export const Card = classOnly(AppCard.Card, 'Card');
export const CardHeader = classOnly(AppCard.CardHeader, 'CardHeader');
export const CardTitle = classOnly(AppCard.CardTitle, 'CardTitle');
export const CardDescription = classOnly(
    AppCard.CardDescription,
    'CardDescription',
);
export const CardContent = classOnly(AppCard.CardContent, 'CardContent');
export const CardFooter = classOnly(AppCard.CardFooter, 'CardFooter');

export const Table = classOnly(AppTable.Table, 'Table');
export const TableHeader = classOnly(AppTable.TableHeader, 'TableHeader');
export const TableBody = classOnly(AppTable.TableBody, 'TableBody');
export const TableFooter = classOnly(AppTable.TableFooter, 'TableFooter');
export const TableRow = classOnly(AppTable.TableRow, 'TableRow');
export const TableHead = spanning(AppTable.TableHead, 'TableHead');
export const TableCell = spanning(AppTable.TableCell, 'TableCell');

export const Skeleton = classOnly(AppSkeleton, 'Skeleton');

const BADGE_VARIANTS = [
    'default',
    'secondary',
    'destructive',
    'outline',
] as const;

export function Badge({
    className,
    variant,
    children,
}: ClassAndChildren & { variant?: unknown }) {
    return (
        <AppBadge
            className={classNameOf(className)}
            variant={oneOf(variant, BADGE_VARIANTS)}
        >
            {children}
        </AppBadge>
    );
}

const BUTTON_VARIANTS = [
    'default',
    'secondary',
    'outline',
    'ghost',
    'destructive',
    'link',
] as const;

const BUTTON_SIZES = ['default', 'sm', 'lg', 'icon', 'icon-sm'] as const;

/**
 * Always `type="button"`. The handler is called with no arguments: a widget
 * never receives the event (`event.view` is `window`). What it throws is
 * rethrown while rendering, so the widget's error boundary reports it like
 * any other failure instead of it vanishing into the console.
 */
export function Button({
    className,
    variant,
    size,
    disabled,
    onClick,
    children,
}: ClassAndChildren & {
    variant?: unknown;
    size?: unknown;
    disabled?: unknown;
    onClick?: unknown;
}) {
    const [failure, setFailure] = useState<{ error: unknown } | null>(null);

    if (failure) {
        throw failure.error;
    }

    return (
        <AppButton
            type="button"
            className={classNameOf(className)}
            variant={oneOf(variant, BUTTON_VARIANTS)}
            size={oneOf(size, BUTTON_SIZES)}
            disabled={disabled === true}
            onClick={
                typeof onClick === 'function'
                    ? () => {
                          try {
                              onClick();
                          } catch (error) {
                              setFailure({ error });
                          }
                      }
                    : undefined
            }
        >
            {children}
        </AppButton>
    );
}

export function Progress({
    className,
    value,
}: {
    className?: unknown;
    value?: unknown;
}) {
    return (
        <AppProgress
            className={classNameOf(className)}
            value={Math.min(100, Math.max(0, finiteNumber(value, 0)))}
        />
    );
}

const SEPARATOR_ORIENTATIONS = ['horizontal', 'vertical'] as const;

export function Separator({
    className,
    orientation,
}: {
    className?: unknown;
    orientation?: unknown;
}) {
    return (
        <AppSeparator
            className={classNameOf(className)}
            orientation={oneOf(orientation, SEPARATOR_ORIENTATIONS)}
        />
    );
}

/** A widget with nothing to show: a short title and an optional line. */
export function EmptyState({
    className,
    title,
    description,
}: {
    className?: unknown;
    title?: unknown;
    description?: unknown;
}) {
    return (
        <div
            data-slot="widget-empty-state"
            className={cn(
                'flex h-full flex-col items-center justify-center gap-1 p-4 text-center',
                classNameOf(className),
            )}
        >
            {typeof title === 'string' && (
                <p className="text-sm font-medium">{title}</p>
            )}
            {typeof description === 'string' && (
                <p className="text-sm text-muted-foreground">{description}</p>
            )}
        </div>
    );
}

import { Link } from '@inertiajs/react';
import { type ComponentProps, type MouseEvent, type ReactNode } from 'react';
import { type UserMenuEntry } from './use-user-menu-model';

type EntryElementProps = Omit<ComponentProps<'a'>, 'href' | 'children'> & {
    entry: UserMenuEntry;
    /** Runs on every pick, before an action's own handler. */
    onNavigate: () => void;
    children: ReactNode;
};

/**
 * The element an entry renders as, whichever view draws it: a button for an
 * action, a new-tab anchor for an external page, an Inertia link otherwise.
 * Extra props are passed through, so a Radix item can render it with
 * `asChild`.
 */
export function UserMenuEntryElement({
    entry,
    onNavigate,
    children,
    onClick,
    ...props
}: EntryElementProps) {
    // Composed, not replaced: a Radix item hands its own click handler down
    // through `asChild`, and that is what closes the menu on a pick.
    const handleClick = (event: MouseEvent<Element>) => {
        onClick?.(event as MouseEvent<HTMLAnchorElement>);
        onNavigate();
    };

    if (entry.kind === 'action') {
        return (
            <button
                type="button"
                {...(props as ComponentProps<'button'>)}
                onClick={(event) => {
                    handleClick(event);
                    entry.onSelect();
                }}
            >
                {children}
            </button>
        );
    }

    if (entry.external) {
        return (
            <a
                {...props}
                href={entry.href}
                target="_blank"
                rel="noopener noreferrer"
                onClick={handleClick}
            >
                {children}
            </a>
        );
    }

    return (
        <Link
            {...(props as Omit<ComponentProps<typeof Link>, 'href'>)}
            href={entry.href}
            prefetch={entry.prefetch ?? true}
            onClick={handleClick}
        >
            {children}
        </Link>
    );
}

/** An entry's trailing hint; the new-tab arrow is decoration only. */
export function UserMenuEntryMeta({
    entry,
    className,
}: {
    entry: UserMenuEntry;
    className: string;
}) {
    if (!entry.meta) {
        return null;
    }

    return (
        <span
            className={className}
            aria-hidden={
                entry.kind === 'link' && entry.external ? true : undefined
            }
        >
            {entry.meta}
        </span>
    );
}

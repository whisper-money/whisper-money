import type { ReactNode } from 'react';

/**
 * Inertia's `Link` for component tests: an anchor that runs the `onClick` it
 * was given and goes nowhere, so a test can click it and read what it sent.
 */
export function FixtureLink({
    children,
    href,
    className,
    onClick,
}: {
    children: ReactNode;
    href: string;
    className?: string;
    onClick?: () => void;
}) {
    return (
        <a
            href={href}
            className={className}
            onClick={(event) => {
                event.preventDefault();
                onClick?.();
            }}
        >
            {children}
        </a>
    );
}

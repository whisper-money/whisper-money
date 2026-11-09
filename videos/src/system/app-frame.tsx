import AppLogo from '@/components/app-logo';
import { type ReactNode } from 'react';

/**
 * A dark app window around a real page or component, laid out at a fixed size
 * so a <Camera> can aim at known coordinates inside it. The header carries the
 * app's own logo; the page content keeps the padding app pages use.
 */
export function AppFrame({
    width,
    height,
    children,
}: {
    width: number;
    height: number;
    children: ReactNode;
}) {
    return (
        <div
            className="flex flex-col overflow-hidden rounded-2xl border border-border bg-background shadow-2xl"
            style={{ width, height }}
        >
            <header className="flex h-14 shrink-0 items-center gap-2 border-b border-sidebar-border/70 px-6">
                <AppLogo />
            </header>
            <main className="flex flex-1 flex-col gap-6 p-6">{children}</main>
        </div>
    );
}

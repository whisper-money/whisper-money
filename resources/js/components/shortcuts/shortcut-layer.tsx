import { ShortcutLayerContext, useShortcutLayer } from '@/hooks/use-shortcut';
import { type ReactNode } from 'react';

/**
 * Makes everything inside it a shortcut layer of its own, on top of the stack
 * while `active`. The modal primitives (Dialog, AlertDialog, Sheet, Drawer)
 * wrap their content in one, so any of them is a layer without asking.
 */
export function ShortcutLayer({
    active = true,
    modal = true,
    children,
}: {
    active?: boolean;
    modal?: boolean;
    children: ReactNode;
}) {
    const layer = useShortcutLayer({ active, modal });

    return (
        <ShortcutLayerContext.Provider value={layer}>
            {children}
        </ShortcutLayerContext.Provider>
    );
}

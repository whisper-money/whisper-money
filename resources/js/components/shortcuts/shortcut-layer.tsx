import { ShortcutLayerContext, useShortcutLayer } from '@/hooks/use-shortcut';
import {
    createContext,
    useContext,
    useState,
    type ComponentType,
    type ReactNode,
} from 'react';

/**
 * Whether the modal around is open. Radix and vaul keep a modal's content
 * mounted through its close animation, and its shortcuts have to stop when it
 * starts closing, not when it finishes: a second ⌘⏎ in those 200ms would save
 * twice. Content outside a `ShortcutLayerRoot` counts as open while mounted.
 */
const ModalOpenContext = createContext(true);

interface OpenProps {
    open?: boolean;
    defaultOpen?: boolean;
    onOpenChange?: (open: boolean) => void;
}

/**
 * Renders a modal primitive's root (Dialog, AlertDialog, Sheet, Drawer) and
 * tells the `ShortcutLayer` in its content whether it is open, controlled or
 * not. The root still owns the state; an uncontrolled one is only mirrored,
 * through the `onOpenChange` it calls on every change.
 */
export function ShortcutLayerRoot<Props extends OpenProps>({
    root: Root,
    ...props
}: Props & { root: ComponentType<Props> }) {
    const { open, defaultOpen, onOpenChange } = props as unknown as Props;
    const [uncontrolledOpen, setUncontrolledOpen] = useState(
        defaultOpen ?? false,
    );

    return (
        <ModalOpenContext.Provider value={open ?? uncontrolledOpen}>
            <Root
                {...(props as unknown as Props)}
                onOpenChange={(next: boolean) => {
                    setUncontrolledOpen(next);
                    onOpenChange?.(next);
                }}
            />
        </ModalOpenContext.Provider>
    );
}

/**
 * Makes everything inside it a shortcut layer of its own, on top of the stack
 * while the modal around it is open. The modal primitives wrap their content in
 * one, so any of them is a layer without asking.
 */
export function ShortcutLayer({
    modal = true,
    children,
}: {
    modal?: boolean;
    children: ReactNode;
}) {
    const open = useContext(ModalOpenContext);
    const layer = useShortcutLayer({ active: open, modal });

    return (
        <ShortcutLayerContext.Provider value={layer}>
            {children}
        </ShortcutLayerContext.Provider>
    );
}

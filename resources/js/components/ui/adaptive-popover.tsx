import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import {
    type ComponentProps,
    type ReactNode,
    type RefObject,
    useEffect,
    useRef,
} from 'react';

/**
 * Above the toaster, which sonner pins at 999999999.
 *
 * A panel is opened on purpose and closes on its own; a toast is ambient, and
 * the categorize prompt in particular never leaves until it is answered. On the
 * phone this also puts the drawer over the toast rather than under it: the
 * drawer owns the bottom of the screen, and that is exactly where the toast sits.
 */
const ABOVE_TOASTS = 'z-[1000000000]';

/**
 * A `Command` picker in a drawer: a taller list than the popover's 300px, and
 * rows tall enough for a thumb.
 */
export const DRAWER_COMMAND_CLASS =
    '[&_[cmdk-list]]:max-h-[55vh] [&_[cmdk-item]]:min-h-11 [&_[cmdk-input]]:text-base';

/** A picker opened as a bottom drawer, with the header it shows there. */
export interface PickerDrawer {
    title: string;
    description?: string;
}

/**
 * A picker's panel, with the caller deciding which one: a popover, or a bottom
 * drawer for the callers that want one (the phone's bulk-actions bar).
 */
export function PopoverOrDrawer({
    asDrawer,
    open,
    onOpenChange,
    trigger,
    title,
    description,
    children,
    align = 'end',
    modal = false,
    className,
    drawerClassName,
    initialFocus,
}: {
    asDrawer: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trigger: ReactNode;
    /** The drawer's name, read out to a screen reader. */
    title: string;
    /** Shows the drawer's header, title included, with this line under it. */
    description?: string;
    children: ReactNode;
    align?: ComponentProps<typeof PopoverContent>['align'];
    modal?: boolean;
    /** Classes of the popover. */
    className?: string;
    /** Classes of the drawer's body. */
    drawerClassName?: string;
    /**
     * The field the drawer focuses as it opens, keyboard included on a phone.
     * The popover needs none: it already focuses its first field.
     */
    initialFocus?: RefObject<HTMLElement | null>;
}) {
    const keyboardHandoff = useKeyboardHandoff(initialFocus);

    if (asDrawer) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerTrigger asChild onClick={keyboardHandoff.onTriggerClick}>
                    {trigger}
                </DrawerTrigger>
                <DrawerContent
                    className={ABOVE_TOASTS}
                    onOpenAutoFocus={keyboardHandoff.onOpenAutoFocus}
                >
                    <DrawerHeader
                        className={description ? 'text-left' : 'sr-only'}
                    >
                        <DrawerTitle>{title}</DrawerTitle>
                        {description && (
                            <DrawerDescription>{description}</DrawerDescription>
                        )}
                    </DrawerHeader>
                    <div
                        className={cn('overflow-y-auto pb-3', drawerClassName)}
                    >
                        {children}
                    </div>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Popover modal={modal} open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                align={align}
                className={cn('overflow-hidden p-0', ABOVE_TOASTS, className)}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}

/**
 * Focuses `target` when the drawer opens, so the phone's keyboard comes up.
 *
 * iOS Safari only raises the keyboard for a focus() made inside the tap
 * itself, and the drawer's field does not exist yet at that moment. So the tap
 * focuses an invisible stand-in input, which raises the keyboard, and the
 * drawer hands focus over to its field the moment it mounts: moving focus
 * from one field to another keeps the keyboard up. The stand-in leaves the
 * page as soon as it loses focus, whether to the drawer or anywhere else.
 */
function useKeyboardHandoff(target?: RefObject<HTMLElement | null>) {
    const standInRef = useRef<HTMLInputElement | null>(null);

    useEffect(() => () => standInRef.current?.remove(), []);

    if (!target) {
        return { onTriggerClick: undefined, onOpenAutoFocus: undefined };
    }

    return {
        onTriggerClick: () => {
            standInRef.current?.remove();
            standInRef.current = focusKeyboardStandIn();
        },
        onOpenAutoFocus: (event: Event) => {
            if (!target.current) {
                standInRef.current?.remove();

                return;
            }
            event.preventDefault();
            target.current.focus({ preventScroll: true });
        },
    };
}

/** How long the stand-in waits for a drawer that may never open. */
const STAND_IN_TIMEOUT_MS = 1000;

/**
 * Focuses an invisible input on screen, raising the keyboard. It removes
 * itself once it loses focus, or after a second if nothing claimed it.
 */
function focusKeyboardStandIn(): HTMLInputElement {
    const input = document.createElement('input');
    input.dataset.keyboardStandIn = '';
    input.setAttribute('aria-hidden', 'true');
    input.tabIndex = -1;
    // 16px so iOS doesn't zoom in on it; on screen, or iOS ignores it.
    input.style.cssText =
        'position:fixed;top:0;left:0;width:1px;height:1px;opacity:0;font-size:16px;pointer-events:none;';
    input.addEventListener('blur', () => input.remove(), { once: true });
    setTimeout(() => input.remove(), STAND_IN_TIMEOUT_MS);
    document.body.appendChild(input);
    input.focus({ preventScroll: true });

    return input;
}

import {
    Drawer,
    DrawerContent,
    DrawerHeader,
    DrawerTitle,
    DrawerTrigger,
} from '@/components/ui/drawer';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import { type ComponentProps, type ReactNode, useEffect, useRef } from 'react';

export type PopoverSide = ComponentProps<typeof PopoverContent>['side'];
export type PopoverAlign = ComponentProps<typeof PopoverContent>['align'];

/**
 * Above the toaster, which sonner pins at 999999999.
 *
 * A panel is opened on purpose and closes on its own; a toast is ambient, and
 * the categorize prompt in particular never leaves until it is answered. Left at
 * the `z-50` these primitives ship with, that prompt paints straight over the
 * streak panel and the bell — the reader opens a panel and reads a toast.
 *
 * On the phone this also puts the drawer over the toast rather than under it,
 * which is the whole point: the drawer owns the bottom of the screen, and that
 * is exactly where the toast sits.
 */
const ABOVE_TOASTS = 'z-[1000000000]';

/**
 * A panel hung off a button in the chrome: a popover on desktop, a bottom
 * drawer on the phone, where a popover would fight the thumb.
 *
 * The bell and the streak pill both do exactly this, so the switch lives here
 * rather than twice — one place to fix when the phone breakpoint moves, and one
 * clone fewer for the duplication check to fail the build over.
 */
export function AdaptivePopover({
    open,
    onOpenChange,
    trigger,
    title,
    children,
    side = 'bottom',
    align = 'end',
    className,
    openOnHover = false,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    trigger: ReactNode;
    /** Read out to a screen reader as the drawer's name. */
    title: string;
    children: ReactNode;
    side?: PopoverSide;
    align?: PopoverAlign;
    className?: string;
    /**
     * Open the popover on hover as well as on click. Desktop only: there is no
     * hover on a phone, and the drawer stays a deliberate tap either way.
     */
    openOnHover?: boolean;
}) {
    const isMobile = useIsMobile();
    const hover = useHoverToOpen(openOnHover && !isMobile, onOpenChange);

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                <DrawerContent className={ABOVE_TOASTS}>
                    <DrawerHeader className="sr-only">
                        <DrawerTitle>{title}</DrawerTitle>
                    </DrawerHeader>
                    <div className="overflow-y-auto pb-3">{children}</div>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Popover open={open} onOpenChange={onOpenChange}>
            <PopoverTrigger asChild {...hover}>
                {trigger}
            </PopoverTrigger>
            <PopoverContent
                side={side}
                align={align}
                className={cn(
                    'w-[360px] overflow-hidden p-0',
                    ABOVE_TOASTS,
                    className,
                )}
                // Hovering is not asking to be moved: pulling focus into the
                // panel the moment the pointer grazes the trigger steals the
                // keyboard from whatever the reader was doing.
                onOpenAutoFocus={
                    openOnHover ? (event) => event.preventDefault() : undefined
                }
                {...hover}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}

/**
 * Open on pointer enter, close on pointer leave — with a beat before closing,
 * so the pointer can cross the gap between the trigger and the panel without
 * the panel disappearing out from under it.
 */
function useHoverToOpen(enabled: boolean, onOpenChange: (open: boolean) => void) {
    const timer = useRef<ReturnType<typeof setTimeout>>(undefined);

    useEffect(() => () => clearTimeout(timer.current), []);

    if (!enabled) {
        return {};
    }

    return {
        onMouseEnter: () => {
            clearTimeout(timer.current);
            onOpenChange(true);
        },
        onMouseLeave: () => {
            timer.current = setTimeout(() => onOpenChange(false), 120);
        },
    };
}

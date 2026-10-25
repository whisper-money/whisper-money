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
import { useIsMobile } from '@/hooks/use-mobile';
import { cn } from '@/lib/utils';
import {
    type ComponentProps,
    type HTMLAttributes,
    type ReactNode,
    useEffect,
    useRef,
} from 'react';

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
 * A `Command` picker in a drawer: a taller list than the popover's 300px, and
 * rows tall enough for a thumb.
 */
export const DRAWER_COMMAND_CLASS =
    '[&_[cmdk-list]]:max-h-[55vh] [&_[cmdk-item]]:min-h-11';

/** A picker opened as a bottom drawer, with the header it shows there. */
export interface PickerDrawer {
    title: string;
    description?: string;
}

/**
 * The panel itself, with the caller deciding which one: a popover, or a bottom
 * drawer. `AdaptivePopover` decides by the viewport; a picker that only some
 * callers want as a drawer (the phone's bulk-actions bar) passes it explicitly.
 */
export function PopoverOrDrawer({
    asDrawer,
    open,
    onOpenChange,
    trigger,
    title,
    description,
    children,
    side = 'bottom',
    align = 'end',
    modal = false,
    className,
    drawerClassName,
    triggerProps,
    contentProps,
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
    side?: PopoverSide;
    align?: PopoverAlign;
    modal?: boolean;
    /** Classes of the popover. */
    className?: string;
    /** Classes of the drawer's body. */
    drawerClassName?: string;
    triggerProps?: HTMLAttributes<HTMLElement>;
    contentProps?: Omit<ComponentProps<typeof PopoverContent>, 'children'>;
}) {
    if (asDrawer) {
        return (
            <Drawer open={open} onOpenChange={onOpenChange}>
                <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                <DrawerContent className={ABOVE_TOASTS}>
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
            <PopoverTrigger asChild {...triggerProps}>
                {trigger}
            </PopoverTrigger>
            <PopoverContent
                side={side}
                align={align}
                {...contentProps}
                className={cn('overflow-hidden p-0', ABOVE_TOASTS, className)}
            >
                {children}
            </PopoverContent>
        </Popover>
    );
}

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

    return (
        <PopoverOrDrawer
            asDrawer={isMobile}
            open={open}
            onOpenChange={onOpenChange}
            trigger={trigger}
            title={title}
            side={side}
            align={align}
            className={cn('w-[360px]', className)}
            triggerProps={hover}
            contentProps={{
                ...hover,
                // Hovering is not asking to be moved: pulling focus into the
                // panel the moment the pointer grazes the trigger steals the
                // keyboard from whatever the reader was doing.
                onOpenAutoFocus: openOnHover
                    ? (event) => event.preventDefault()
                    : undefined,
            }}
        >
            {children}
        </PopoverOrDrawer>
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

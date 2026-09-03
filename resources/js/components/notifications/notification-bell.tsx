import { index } from '@/actions/App/Http/Controllers/NotificationController';
import { NotificationList } from '@/components/notifications/notification-list';
import { useMarkAllRead } from '@/components/notifications/use-mark-all-read';
import { Button } from '@/components/ui/button';
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
import { type NotificationItem, type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { BellIcon, CheckCheckIcon } from 'lucide-react';
import { type ComponentProps, useState } from 'react';

/**
 * The bell next to the account: a badge with the unread count and a panel with
 * the latest rows.
 *
 * One component, two homes: the sidebar footer on desktop, where the panel is a
 * popover that opens away from the edge, and the header on the phone, where a
 * popover would fight the thumb and the panel is a bottom drawer instead.
 *
 * "Mark all as read" hides the dots at once and fires in the background; see
 * `useMarkAllRead`.
 */
const NO_ROWS: NotificationItem[] = [];

export function NotificationBell({
    className,
    side = 'bottom',
    align = 'end',
}: {
    className?: string;
    side?: ComponentProps<typeof PopoverContent>['side'];
    align?: ComponentProps<typeof PopoverContent>['align'];
}) {
    const { notifications } = usePage<SharedData>().props;
    const isMobile = useIsMobile();
    const [open, setOpen] = useState(false);
    const { items, unread, markAllRead } = useMarkAllRead(
        notifications?.recent ?? NO_ROWS,
        notifications?.unread,
    );

    if (!notifications) {
        return null;
    }

    const trigger = (
        <Button
            variant="ghost"
            size="icon"
            className={cn('relative size-8', className)}
            aria-label={
                unread > 0
                    ? __(':count unread notifications', { count: unread })
                    : __('Notifications')
            }
        >
            <BellIcon className="size-5" />
            {unread > 0 && (
                <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-foreground px-1 text-[10px] leading-none font-semibold text-background tabular-nums">
                    {unread > 9 ? '9+' : unread}
                </span>
            )}
        </Button>
    );

    const panel = (
        <>
            <div className="flex items-center justify-between border-b px-3 py-2.5">
                <span className="text-sm font-medium">
                    {__('Notifications')}
                </span>
                {unread > 0 && (
                    <button
                        type="button"
                        onClick={markAllRead}
                        className="flex cursor-pointer items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                    >
                        <CheckCheckIcon className="size-3" />
                        {__('Mark all as read')}
                    </button>
                )}
            </div>
            {items.length === 0 ? (
                <p className="m-3 rounded-lg border border-dashed px-5 py-7 text-center text-[13px] text-pretty text-muted-foreground">
                    {__(
                        'Nothing here yet. Updates about your money show up here as they happen.',
                    )}
                </p>
            ) : (
                <>
                    <NotificationList
                        items={items}
                        onNavigate={() => setOpen(false)}
                    />
                    <Link
                        href={index().url}
                        onClick={() => setOpen(false)}
                        className="block border-t py-2.5 text-center text-[13px] font-medium transition-colors hover:bg-accent"
                    >
                        {__('View all notifications')}
                    </Link>
                </>
            )}
        </>
    );

    if (isMobile) {
        return (
            <Drawer open={open} onOpenChange={setOpen}>
                <DrawerTrigger asChild>{trigger}</DrawerTrigger>
                <DrawerContent>
                    <DrawerHeader className="sr-only">
                        <DrawerTitle>{__('Notifications')}</DrawerTitle>
                    </DrawerHeader>
                    <div className="overflow-y-auto pb-3">{panel}</div>
                </DrawerContent>
            </Drawer>
        );
    }

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>{trigger}</PopoverTrigger>
            <PopoverContent
                side={side}
                align={align}
                className="w-[360px] overflow-hidden p-0"
            >
                {panel}
            </PopoverContent>
        </Popover>
    );
}

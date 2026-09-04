import { index } from '@/actions/App/Http/Controllers/NotificationController';
import { NotificationList } from '@/components/notifications/notification-list';
import { useMarkAllRead } from '@/components/notifications/use-mark-all-read';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type NotificationItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head } from '@inertiajs/react';
import { CheckCheckIcon } from 'lucide-react';

/**
 * Everything the bell has ever shown, newest first.
 *
 * The panel keeps the latest few; this is where the rest lives. Same rows, with
 * more room, and one button to clear the lot.
 */
interface Props {
    notifications: NotificationItem[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Notifications', href: index().url },
];

export default function NotificationsIndex({ notifications }: Props) {
    const { items, unread, markAllRead } = useMarkAllRead(notifications);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Notifications')} />

            <div className="mx-auto flex w-full max-w-page flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex flex-col gap-1">
                        <h1 className="text-xl font-semibold tracking-tight">
                            {__('Notifications')}
                        </h1>
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'Everything that happened in your account, newest first.',
                            )}
                        </p>
                    </div>
                    {unread > 0 && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={markAllRead}
                        >
                            <CheckCheckIcon />
                            {__('Mark all as read')}
                        </Button>
                    )}
                </div>

                {items.length === 0 ? (
                    <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                        {__(
                            'Nothing here yet. Updates about your money show up here as they happen.',
                        )}
                    </p>
                ) : (
                    <div className="overflow-hidden rounded-lg border">
                        <NotificationList items={items} roomy />
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

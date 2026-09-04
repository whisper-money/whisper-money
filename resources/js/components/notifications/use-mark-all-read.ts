import { readAll } from '@/actions/App/Http/Controllers/NotificationController';
import { type NotificationItem } from '@/types';
import { useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';

/**
 * "Mark all as read" without a page visit.
 *
 * The rows lose their dots at once and the request goes out in the background,
 * the way the dashboard notice is dismissed: a full visit would reload every
 * deferred prop on the page just to clear a badge. A fresh set of rows from the
 * server outranks the local shortcut, so the next navigation brings the truth
 * back either way.
 */
export function useMarkAllRead(
    source: NotificationItem[],
    unreadCount?: number,
) {
    const [allRead, setAllRead] = useState(false);
    const { post } = useHttp();

    useEffect(() => setAllRead(false), [source]);

    const items = allRead
        ? source.map((item) => ({
              ...item,
              read_at: item.read_at ?? new Date().toISOString(),
          }))
        : source;

    const unread = allRead
        ? 0
        : (unreadCount ??
          source.filter((item) => item.read_at === null).length);

    const markAllRead = () => {
        setAllRead(true);
        post(readAll().url);
    };

    return { items, unread, markAllRead };
}

import { show } from '@/actions/App/Http/Controllers/NotificationController';
import { useLocale } from '@/hooks/use-locale';
import { cn } from '@/lib/utils';
import { type NotificationItem, type NotificationKind } from '@/types';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { BellIcon, FileTextIcon, type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

/**
 * The rows of the bell, grouped by day.
 *
 * Shared by the panel and the full page, so a row reads the same in both: a
 * glyph that says what kind of thing happened, an eyebrow naming it, the title,
 * a line of detail, and a dot while it is unread. Each row is a plain link to
 * `notifications/{id}`, which records the read and lands where the row points.
 */

function startOfDay(date: Date): number {
    return new Date(
        date.getFullYear(),
        date.getMonth(),
        date.getDate(),
    ).getTime();
}

function dayLabel(date: Date, locale: string): string {
    const today = startOfDay(new Date());
    const day = startOfDay(date);
    const dayMs = 24 * 60 * 60 * 1000;

    if (day === today) {
        return __('Today');
    }

    if (day === today - dayMs) {
        return __('Yesterday');
    }

    return date.toLocaleDateString(locale, { day: 'numeric', month: 'long' });
}

/**
 * Short and relative while it is fresh, a date once it is not. The units stay
 * untranslated on purpose: "3h" reads the same in every language the app has.
 */
function timeLabel(date: Date, locale: string): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - date.getTime()) / 60000),
    );

    if (minutes < 60) {
        return `${minutes}m`;
    }

    if (minutes < 24 * 60) {
        return `${Math.round(minutes / 60)}h`;
    }

    return date.toLocaleDateString(locale, { day: 'numeric', month: 'short' });
}

export function groupByDay(
    items: NotificationItem[],
    locale: string,
): { label: string; items: NotificationItem[] }[] {
    const groups: { label: string; items: NotificationItem[] }[] = [];

    for (const item of items) {
        const label = dayLabel(new Date(item.created_at), locale);
        const last = groups[groups.length - 1];

        if (last && last.label === label) {
            last.items.push(item);
        } else {
            groups.push({ label, items: [item] });
        }
    }

    return groups;
}

/**
 * Everything a kind of notification looks like, in one place: a new tenant is
 * one entry here and one case in `NotificationFeed`, nothing else.
 */
const KINDS: Record<
    NotificationKind,
    { label: () => string; icon: LucideIcon }
> = {
    monthly_summary: { label: () => __('Monthly summary'), icon: FileTextIcon },
    other: { label: () => __('Notification'), icon: BellIcon },
};

/**
 * Square tile with a document for a report, so it reads apart at a glance from
 * the round medal an achievement will get.
 */
function NotificationGlyph({ kind }: { kind: NotificationKind }): ReactNode {
    const Icon = KINDS[kind].icon;

    return (
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md border bg-card">
            <Icon className="size-4" />
        </span>
    );
}

export function NotificationRow({
    item,
    roomy = false,
    onNavigate,
}: {
    item: NotificationItem;
    roomy?: boolean;
    onNavigate?: () => void;
}) {
    const locale = useLocale();
    const unread = item.read_at === null;

    return (
        <Link
            href={show(item.id).url}
            onClick={onNavigate}
            className={cn(
                'flex gap-3 transition-colors hover:bg-accent',
                roomy ? 'px-4 py-3.5' : 'px-3 py-2.5',
                unread && 'bg-muted/40',
            )}
        >
            <div className="pt-0.5">
                <NotificationGlyph kind={item.kind} />
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <span className="text-[10px] font-semibold tracking-widest text-muted-foreground uppercase">
                    {KINDS[item.kind].label()}
                </span>
                <span
                    className={cn(
                        'text-sm text-pretty',
                        unread && 'font-medium',
                    )}
                >
                    {item.title}
                </span>
                {item.body && (
                    <span className="text-xs text-pretty text-muted-foreground">
                        {item.body}
                    </span>
                )}
            </div>
            <div className="flex shrink-0 flex-col items-end gap-2 pt-4">
                <span className="text-[11px] text-muted-foreground tabular-nums">
                    {timeLabel(new Date(item.created_at), locale)}
                </span>
                {unread && (
                    <span
                        className="block size-1.5 rounded-full bg-foreground"
                        aria-label={__('Unread')}
                    />
                )}
            </div>
        </Link>
    );
}

export function NotificationList({
    items,
    roomy = false,
    onNavigate,
}: {
    items: NotificationItem[];
    roomy?: boolean;
    onNavigate?: () => void;
}) {
    const locale = useLocale();

    return (
        <div className="divide-y">
            {groupByDay(items, locale).map((group) => (
                <div key={group.label}>
                    <div
                        className={cn(
                            'pt-2.5 pb-1 text-xs font-medium text-muted-foreground',
                            roomy ? 'px-4' : 'px-3',
                        )}
                    >
                        {group.label}
                    </div>
                    {group.items.map((item) => (
                        <NotificationRow
                            key={item.id}
                            item={item}
                            roomy={roomy}
                            onNavigate={onNavigate}
                        />
                    ))}
                </div>
            ))}
        </div>
    );
}

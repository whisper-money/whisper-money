import { show } from '@/actions/App/Http/Controllers/MonthlySummaryController';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * The notice that a new monthly summary is ready.
 *
 * The email is one way in, not the only one: someone who never opens email still
 * gets the report, and the card that goes with it. It appears when the summary
 * exists rather than on a fixed day, because the send window spans eight days.
 *
 * Dismissal is remembered per browser rather than server-side. It is a monthly
 * notice, so "I closed it on this device" is as much memory as it needs, and it
 * costs no column and no request.
 */
export type MonthlySummaryNoticeData = {
    id: string;
    monthLabel: string;
    headline: string;
};

const STORAGE_PREFIX = 'monthly-summary-dismissed:';

function alreadyDismissed(id: string): boolean {
    try {
        return localStorage.getItem(STORAGE_PREFIX + id) !== null;
    } catch {
        // A private window or blocked site data: showing the notice again beats
        // crashing the dashboard over it.
        return false;
    }
}

export default function MonthlySummaryNotice({
    summary,
}: {
    summary: MonthlySummaryNoticeData;
}) {
    const [dismissed, setDismissed] = useState(() =>
        alreadyDismissed(summary.id),
    );

    if (dismissed) {
        return null;
    }

    const dismiss = () => {
        try {
            localStorage.setItem(STORAGE_PREFIX + summary.id, '1');
        } catch {
            // Nothing to do: it will show again next load, which is harmless.
        }

        setDismissed(true);
    };

    return (
        <div className="flex flex-wrap items-center gap-4 rounded-lg border bg-muted/40 p-4">
            <div className="flex min-w-0 flex-1 flex-col gap-1">
                <span className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                    {__('Your :month summary is ready', {
                        month: summary.monthLabel,
                    })}
                </span>
                <p className="text-sm text-pretty">{summary.headline}</p>
            </div>
            <Button size="sm" asChild>
                <Link href={show(summary.id).url}>{__('Open it')}</Link>
            </Button>
            <Button
                size="icon"
                variant="ghost"
                onClick={dismiss}
                aria-label={__('Dismiss')}
            >
                <XIcon className="size-4" />
            </Button>
        </div>
    );
}

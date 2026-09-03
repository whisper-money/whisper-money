import {
    dismiss,
    show,
} from '@/actions/App/Http/Controllers/MonthlySummaryController';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { Link, useHttp } from '@inertiajs/react';
import { XIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * The notice that a new monthly summary is ready.
 *
 * The email is one way in, not the only one: someone who never opens email still
 * gets the report, and the card that goes with it. It appears when the summary
 * exists rather than on a fixed day, because the send window spans eight days.
 *
 * Dismissal is stored on the summary itself, so closing it once puts it away on
 * every device and across logins. The banner disappears the moment it is clicked
 * and the request goes out on its own: a full Inertia visit would reload every
 * deferred prop on the dashboard just to hide one row. Should the request fail,
 * the notice is back on the next load, which is harmless.
 */
export type MonthlySummaryNoticeData = {
    id: string;
    monthLabel: string;
    headline: string;
};

export default function MonthlySummaryNotice({
    summary,
}: {
    summary: MonthlySummaryNoticeData;
}) {
    const [dismissed, setDismissed] = useState(false);
    const { post } = useHttp();

    if (dismissed) {
        return null;
    }

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
                onClick={() => {
                    setDismissed(true);
                    post(dismiss(summary.id).url);
                }}
                aria-label={__('Dismiss')}
            >
                <XIcon className="size-4" />
            </Button>
        </div>
    );
}

import {
    index,
    revoke,
    share,
    show,
} from '@/actions/App/Http/Controllers/MonthlySummaryController';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { Skeleton } from '@/components/ui/skeleton';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { __ } from '@/utils/i18n';
import { Head, router } from '@inertiajs/react';
import { CheckIcon, CopyIcon, DownloadIcon, SparklesIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * One month's report, and the cards it can produce.
 *
 * The figures come frozen from the server and are printed as they were sent: a
 * report about a closed month that changed after the fact would be worse than no
 * report. The sentences arrive as HTML because they are assembled from
 * translated fragments with emphasis on the numbers; every value interpolated
 * into them is escaped server-side.
 */
type Row = { text: string };
type Todo = { text: string; action: string };
type CardFormat = { format: string; url: string };
type CardOption = {
    card: string;
    chosen: boolean;
    preview: string;
    formats: CardFormat[];
};

interface Props {
    summary: { id: string; period: string; complete: boolean; shared: boolean };
    report: {
        monthLabel: string;
        headline: string;
        lede: string;
        rows: Row[];
        todos: Todo[];
    };
    analysis: string | null;
    cards: CardOption[];
    shareUrl: string | null;
}

const FORMAT_LABELS: Record<string, string> = {
    feed: '4:5',
    story: '9:16',
    wide: '16:9',
};

function cardLabel(card: string): string {
    const labels: Record<string, string> = {
        streak: __('Streak'),
        savings_rate: __('Savings rate'),
        spending_split: __('Where it went'),
        net_worth: __('Net worth'),
        savings_goal: __('Savings goal'),
    };

    return labels[card] ?? card;
}

/**
 * The card as it will be posted, at the 4:5 the feeds want.
 *
 * The PNG is drawn on the server the first time anybody asks for it, so a
 * preview can take a moment to arrive and can fail outright. Neither may leave a
 * broken image sitting in the middle of the grid.
 */
function CardPreview({ label, src }: { label: string; src: string }) {
    const [status, setStatus] = useState<'loading' | 'ready' | 'failed'>(
        'loading',
    );
    // A render that timed out usually works on the next go, and the browser
    // holds on to the failure, so a retry needs a URL it has not seen.
    const [attempt, setAttempt] = useState(0);

    if (status === 'failed') {
        return (
            <div className="flex size-full flex-col items-center justify-center gap-2 p-4 text-center">
                <p className="text-xs text-muted-foreground">
                    {__('This picture could not be drawn.')}
                </p>
                <Button
                    variant="outline"
                    size="sm"
                    onClick={() => {
                        setAttempt(attempt + 1);
                        setStatus('loading');
                    }}
                >
                    {__('Try again')}
                </Button>
            </div>
        );
    }

    return (
        <>
            {status === 'loading' && (
                <Skeleton className="absolute inset-0 rounded-none" />
            )}
            <img
                src={attempt === 0 ? src : `${src}&retry=${attempt}`}
                alt={label}
                loading="lazy"
                decoding="async"
                className={cn(
                    'size-full object-cover transition-opacity',
                    status === 'ready' ? 'opacity-100' : 'opacity-0',
                )}
                onLoad={() => setStatus('ready')}
                onError={() => setStatus('failed')}
            />
        </>
    );
}

export default function MonthlySummaryShow({
    summary,
    report,
    analysis,
    cards,
    shareUrl,
}: Props) {
    const [copied, setCopied] = useState(false);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Summaries', href: index().url },
        { title: report.monthLabel, href: show(summary.id).url },
    ];

    const copyShareUrl = async () => {
        if (shareUrl === null) {
            return;
        }

        await navigator.clipboard.writeText(shareUrl);
        setCopied(true);
        window.setTimeout(() => setCopied(false), 2000);
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={report.monthLabel} />

            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8 p-4">
                <div className="flex flex-col gap-2">
                    <span className="text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                        {report.monthLabel}
                    </span>
                    <h1 className="text-2xl leading-tight font-bold tracking-tight text-pretty">
                        {report.headline}
                    </h1>
                    <p className="text-muted-foreground">{report.lede}</p>
                    {!summary.complete && (
                        <p className="text-sm text-muted-foreground">
                            {__(
                                'Some of your accounts had not reported when this was worked out, so the figures may be short.',
                            )}
                        </p>
                    )}
                </div>

                {analysis !== null && (
                    <div className="flex flex-col gap-3 border-l-4 border-primary bg-muted/50 p-5">
                        <span className="flex items-center gap-2 text-xs font-semibold tracking-widest text-muted-foreground uppercase">
                            <SparklesIcon className="size-3.5" />
                            {__('Why this happened')}
                        </span>
                        {analysis.split(/\n{2,}/).map((paragraph, index) => (
                            <p key={index} className="text-sm leading-relaxed">
                                {paragraph.trim()}
                            </p>
                        ))}
                    </div>
                )}

                <div className="flex flex-col gap-4">
                    <h2 className="text-sm font-semibold">
                        {__('Share your month')}
                    </h2>
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3">
                        {cards.map((card) => (
                            <div
                                key={card.card}
                                className="flex flex-col gap-2"
                            >
                                <span className="text-sm font-medium">
                                    {cardLabel(card.card)}
                                    {card.chosen && (
                                        <span className="ml-1.5 text-xs font-normal text-muted-foreground">
                                            {__('picked for you')}
                                        </span>
                                    )}
                                </span>

                                <div
                                    className={cn(
                                        'relative aspect-[4/5] overflow-hidden rounded-md border bg-card',
                                        card.chosen &&
                                            'ring-2 ring-primary ring-offset-2 ring-offset-background',
                                    )}
                                >
                                    <CardPreview
                                        label={cardLabel(card.card)}
                                        src={card.preview}
                                    />
                                </div>

                                <ButtonGroup className="w-full">
                                    {card.formats.map((format) => (
                                        <Button
                                            key={format.format}
                                            variant="outline"
                                            size="sm"
                                            className="h-9 flex-1 text-xs sm:h-8"
                                            asChild
                                        >
                                            <a href={format.url}>
                                                <DownloadIcon className="size-3.5" />
                                                {FORMAT_LABELS[format.format] ??
                                                    format.format}
                                            </a>
                                        </Button>
                                    ))}
                                </ButtonGroup>
                            </div>
                        ))}
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {shareUrl === null ? (
                            <>
                                <Button
                                    variant="secondary"
                                    size="sm"
                                    onClick={() =>
                                        router.post(share(summary.id).url)
                                    }
                                >
                                    {__('Create a public link')}
                                </Button>
                                <p className="text-xs text-muted-foreground">
                                    {__(
                                        'Anyone with the link sees the image and nothing else. No link exists until you make one.',
                                    )}
                                </p>
                            </>
                        ) : (
                            <>
                                <code className="rounded bg-muted px-2 py-1 text-xs">
                                    {shareUrl}
                                </code>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={copyShareUrl}
                                >
                                    {copied ? (
                                        <CheckIcon className="size-3.5" />
                                    ) : (
                                        <CopyIcon className="size-3.5" />
                                    )}
                                    {copied ? __('Copied') : __('Copy')}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(revoke(summary.id).url)
                                    }
                                >
                                    {__('Revoke the link')}
                                </Button>
                            </>
                        )}
                    </div>
                </div>

                <div className="flex flex-col gap-4">
                    <h2 className="text-sm font-semibold">
                        {__('The figures')}
                    </h2>
                    <ul className="divide-y rounded-lg border">
                        {report.rows.map((row, index) => (
                            <li
                                key={index}
                                className="p-4 text-sm leading-relaxed text-muted-foreground [&_strong]:font-semibold [&_strong]:text-foreground"
                                dangerouslySetInnerHTML={{ __html: row.text }}
                            />
                        ))}
                    </ul>
                </div>

                {report.todos.length > 0 && (
                    <div className="flex flex-col gap-4">
                        <h2 className="text-sm font-semibold">
                            {__('Worth five minutes')}
                        </h2>
                        <ul className="divide-y rounded-lg border">
                            {report.todos.map((todo, index) => (
                                <li
                                    key={index}
                                    className="p-4 text-sm leading-relaxed text-muted-foreground [&_strong]:font-semibold [&_strong]:text-foreground"
                                    dangerouslySetInnerHTML={{
                                        __html: todo.text,
                                    }}
                                />
                            ))}
                        </ul>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}

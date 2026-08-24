import Header from '@/components/partials/header';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { type SharedData } from '@/types';
import { formatDateMedium } from '@/utils/date';
import { __ } from '@/utils/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { ExternalLinkIcon } from 'lucide-react';

const FEEDBACK_URL = 'https://whispermoney.userjot.com/';

type RoadmapStatus = 'planned' | 'in_progress' | 'shipped';

type RoadmapItem = {
    title: string;
    body: string;
    status: RoadmapStatus;
    date: string;
    url: string;
};

const STATUS_STYLES: Record<RoadmapStatus, { dot: string; label: string }> = {
    planned: {
        dot: 'bg-[#c9c8c3] dark:bg-[#5a5a55]',
        label: 'text-[#a3a29d] dark:text-[#7a7975]',
    },
    in_progress: {
        dot: 'bg-amber-500',
        label: 'text-amber-600 dark:text-amber-500',
    },
    shipped: {
        dot: 'bg-emerald-600 dark:bg-emerald-500',
        label: 'text-emerald-700 dark:text-emerald-500',
    },
};

function statusLabel(status: RoadmapStatus): string {
    if (status === 'planned') {
        return __('Planned');
    }

    if (status === 'in_progress') {
        return __('In progress');
    }

    return __('Shipped');
}

export default function Roadmap({
    canRegister = false,
    items,
}: {
    canRegister?: boolean;
    items: RoadmapItem[];
}) {
    const { appUrl, locale } = usePage<SharedData>().props;
    const description = __(
        "See what's shipped, what we're building, and what's coming next in Whisper Money.",
    );

    return (
        <>
            {/* The Inertia title callback already appends " - Whisper Money". */}
            <Head title={__('Roadmap')}>
                <meta name="description" content={description} />
                <link rel="canonical" href={`${appUrl}/roadmap`} />
                <meta name="robots" content="index, follow" />
                <meta
                    property="og:title"
                    content={__('Roadmap - Whisper Money')}
                />
                <meta property="og:description" content={description} />
                <meta property="og:type" content="website" />
                <meta property="og:url" content={`${appUrl}/roadmap`} />
            </Head>

            <div className="flex min-h-screen flex-col bg-[#FDFDFC] text-[#1b1b18] dark:bg-[#0a0a0a] dark:text-[#EDEDEC]">
                <Header canRegister={canRegister} />

                <main className="flex flex-1 flex-col px-6 py-28 sm:py-32">
                    <div className="mx-auto flex w-full max-w-3xl flex-col gap-12">
                        <div className="flex flex-col items-center gap-4 text-center">
                            <h1 className="font-heading text-4xl font-semibold sm:text-5xl">
                                {__('Roadmap.')}
                            </h1>
                            <p className="text-lg text-[#706f6c] dark:text-[#A1A09A]">
                                {__("What's shipped. What's coming.")}
                            </p>
                            <a
                                href={FEEDBACK_URL}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button
                                    variant="outline"
                                    className="cursor-pointer rounded-full"
                                >
                                    {__('Request a feature')}
                                    <ExternalLinkIcon className="size-4 opacity-60" />
                                </Button>
                            </a>
                        </div>

                        {items.length === 0 ? (
                            <p className="text-center text-[#706f6c] dark:text-[#A1A09A]">
                                {__(
                                    'The roadmap is temporarily unavailable. Check it out on our feedback board.',
                                )}
                            </p>
                        ) : (
                            <ol className="relative flex flex-col gap-10 border-l border-[#e3e3e0] pl-8 dark:border-[#3E3E3A]">
                                {items.map((item) => (
                                    <li
                                        key={item.title}
                                        className="relative flex flex-col gap-2"
                                    >
                                        <span
                                            className={cn(
                                                'absolute top-1.5 -left-[2.125rem] size-2.5 rounded-full ring-4 ring-[#FDFDFC] dark:ring-[#0a0a0a]',
                                                STATUS_STYLES[item.status].dot,
                                            )}
                                        />
                                        <div className="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                            <h2 className="font-mono text-lg font-medium">
                                                <a
                                                    href={item.url}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="decoration-[#c9c8c3] underline-offset-4 hover:underline dark:decoration-[#5a5a55]"
                                                >
                                                    {item.title}
                                                </a>
                                            </h2>
                                            <span
                                                className={cn(
                                                    'font-mono text-xs tracking-wider uppercase',
                                                    STATUS_STYLES[item.status]
                                                        .label,
                                                )}
                                            >
                                                {statusLabel(item.status)}
                                            </span>
                                            <span className="font-mono text-xs text-[#a3a29d] dark:text-[#7a7975]">
                                                {formatDateMedium(
                                                    item.date,
                                                    locale,
                                                )}
                                            </span>
                                        </div>
                                        {item.body && (
                                            <p className="line-clamp-3 text-[#706f6c] dark:text-[#A1A09A]">
                                                {item.body}
                                            </p>
                                        )}
                                    </li>
                                ))}
                            </ol>
                        )}
                    </div>
                </main>

                <footer className="py-8">
                    <div className="mx-auto max-w-3xl px-6 text-sm text-[#706f6c] dark:text-[#A1A09A]">
                        <Link
                            href="/"
                            className="hover:text-[#1b1b18] dark:hover:text-[#EDEDEC]"
                        >
                            {__('← Back to home')}
                        </Link>
                    </div>
                </footer>
            </div>
        </>
    );
}

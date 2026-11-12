import { CustomDashboardsPreview } from '@/components/landing/custom-dashboards-preview';
import documentation from '@/routes/documentation';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    ArrowRightIcon,
    LayoutDashboardIcon,
    LockIcon,
    type LucideIcon,
    MessageSquareIcon,
} from 'lucide-react';

/** The custom dashboards page of the documentation; sections stay out of slugs. */
const DOCS_SLUG = 'custom-dashboards';

const POINTS: ReadonlyArray<{
    icon: LucideIcon;
    title: string;
    body: string;
    short: string;
}> = [
    {
        icon: MessageSquareIcon,
        title: 'Built from one sentence',
        body: 'Say what you want to see. Your assistant writes the widget, checks it and puts it on the dashboard you pick. Ask again to change it.',
        short: 'Say what you want to see; your assistant writes and places it.',
    },
    {
        icon: LockIcon,
        title: 'Locked down by design',
        body: 'A widget only reads your own data from Whisper Money, with your session. Its code is checked before it runs and cannot reach anything else.',
        short: 'It only reads your data, with your session. Nothing else.',
    },
    {
        icon: LayoutDashboardIcon,
        title: 'Yours to arrange',
        body: 'Several dashboards on a 12-column grid. Drag widgets around, resize them, and go back to an earlier version whenever you want.',
        short: 'Several dashboards. Drag, resize, go back a version.',
    },
];

/**
 * The landing's custom dashboards block: the assistant writing a widget onto a
 * dashboard, and what that means for the visitor. Phones get the same preview,
 * tighter, and the three points as one card with a short line each.
 */
export function CustomDashboardsSection({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    return (
        <section
            aria-labelledby="custom-dashboards-heading"
            className="px-4 py-12 sm:py-16 md:py-20"
        >
            <div className="mx-auto flex max-w-7xl flex-col gap-6 sm:gap-12">
                <div className="flex flex-col gap-3.5 sm:gap-4.5">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="inline-flex items-center gap-2 rounded-full border border-[#e3e3e0] px-2.5 py-1 text-[0.8rem] font-medium dark:border-[#3E3E3A]">
                            <LayoutDashboardIcon
                                aria-hidden="true"
                                className="size-3.5 opacity-75"
                            />
                            <span className="text-[#706f6c] dark:text-[#A1A09A]">
                                {__('Custom dashboards')}
                            </span>
                        </span>
                        <span className="rounded-full bg-emerald-500/12 px-2.25 py-0.75 text-xs font-semibold text-emerald-700 dark:bg-emerald-400/14 dark:text-emerald-300">
                            {__('New')}
                        </span>
                    </div>
                    <h2
                        id="custom-dashboards-heading"
                        className="max-w-[820px] text-3xl leading-tight font-semibold tracking-tight text-balance sm:text-4xl sm:leading-tight md:text-5xl md:leading-tight"
                    >
                        {__('Ask for a chart. It lands on your dashboard.')}
                    </h2>
                    <p className="text-md max-w-[680px] font-medium text-[#706f6c] sm:text-lg dark:text-[#A1A09A]">
                        {__(
                            'Tell your AI assistant what you want to keep an eye on and it builds the widget for you: a live chart on your real numbers, placed on a dashboard you arrange yourself.',
                        )}
                    </p>
                </div>

                <CustomDashboardsPreview currency={currency} locale={locale} />

                <div className="hidden gap-3 sm:grid sm:grid-cols-[repeat(auto-fit,minmax(260px,1fr))]">
                    {POINTS.map(({ icon: Icon, title, body }) => (
                        <div
                            key={title}
                            className="flex flex-col gap-3 rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC] p-6 lg:p-8 dark:border-[#3E3E3A] dark:bg-[#161615]"
                        >
                            <span className="inline-flex size-9 items-center justify-center rounded-[10px] border border-[#e3e3e0] dark:border-[#3E3E3A]">
                                <Icon
                                    aria-hidden="true"
                                    className="size-4.5 text-emerald-600 dark:text-emerald-400"
                                />
                            </span>
                            <h3 className="text-xl leading-snug font-semibold">
                                {__(title)}
                            </h3>
                            <p className="text-sm leading-relaxed text-[#706f6c] dark:text-[#A1A09A]">
                                {__(body)}
                            </p>
                        </div>
                    ))}
                </div>

                <ul className="flex flex-col divide-y divide-[#e3e3e0] rounded-[14px] border border-[#e3e3e0] bg-[#FDFDFC] sm:hidden dark:divide-[#3E3E3A] dark:border-[#3E3E3A] dark:bg-[#161615]">
                    {POINTS.map(({ icon: Icon, title, short }) => (
                        <li
                            key={title}
                            className="flex items-start gap-3 px-4 py-3.5"
                        >
                            <span className="inline-flex size-7 shrink-0 items-center justify-center rounded-lg border border-[#e3e3e0] dark:border-[#3E3E3A]">
                                <Icon
                                    aria-hidden="true"
                                    className="size-3.75 text-emerald-600 dark:text-emerald-400"
                                />
                            </span>
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-[0.9375rem] font-semibold">
                                    {__(title)}
                                </span>
                                <span className="text-[0.8125rem] leading-normal text-[#706f6c] dark:text-[#A1A09A]">
                                    {__(short)}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>

                <div className="flex flex-wrap items-center justify-between gap-x-6 gap-y-2">
                    <p className="text-[0.8125rem] text-[#706f6c] dark:text-[#A1A09A]">
                        {__(
                            'On the paid plan, with Claude or ChatGPT connected to Whisper Money.',
                        )}
                    </p>
                    <Link
                        href={documentation.show(DOCS_SLUG)}
                        className="inline-flex min-h-11 items-center gap-1.5 text-sm font-medium underline underline-offset-4 hover:text-[#706f6c] dark:hover:text-[#A1A09A]"
                    >
                        {__('How custom dashboards work')}
                        <ArrowRightIcon aria-hidden="true" className="size-4" />
                    </Link>
                </div>
            </div>
        </section>
    );
}

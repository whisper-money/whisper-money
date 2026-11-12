import { useScrollProgress } from '@/hooks/use-scroll-progress';
import { riseStyle, stepProgress } from '@/lib/scroll-reveal';
import { cn } from '@/lib/utils';
import { formatCurrency } from '@/utils/currency';
import { __ } from '@/utils/i18n';
import {
    CheckIcon,
    ChevronDownIcon,
    PencilIcon,
    SparklesIcon,
} from 'lucide-react';
import { type CSSProperties, type ReactNode } from 'react';

/**
 * The preview's timeline runs from 0 to 1 like the scroll progress, but ends
 * here rather than when the preview has left the viewport: past this point
 * the widget would finish drawing above the fold, where nobody sees it.
 */
const TIMELINE_END = 0.6;

/** A step of the timeline; `stagger` delays each item of a list after the first. */
type Step = { start: number; span: number; stagger?: number };

/** When each step starts on the timeline, and how long it takes. */
const STEPS = {
    request: { start: 0.05, span: 0.12 },
    tool: { start: 0.18, span: 0.1, stagger: 0.07 },
    widgetSlot: { start: 0.32, span: 0.12 },
    bar: { start: 0.46, span: 0.16, stagger: 0.015 },
    reply: { start: 0.66, span: 0.12 },
    badge: { start: 0.74, span: 0.1 },
    ringSoftens: { start: 0.9, span: 0.1 },
} satisfies Record<string, Step>;

/** Groceries per month in cents, January to October. */
const GROCERIES_BY_MONTH = [
    { lastYear: 38000, thisYear: 34200 },
    { lastYear: 35500, thisYear: 33100 },
    { lastYear: 40200, thisYear: 36500 },
    { lastYear: 39000, thisYear: 34800 },
    { lastYear: 41000, thisYear: 37200 },
    { lastYear: 39800, thisYear: 35000 },
    { lastYear: 43000, thisYear: 38900 },
    { lastYear: 38500, thisYear: 34100 },
    { lastYear: 39500, thisYear: 35600 },
    { lastYear: 42000, thisYear: 36100 },
] as const;
/** The top of the chart's scale, a little above the tallest month. */
const GROCERIES_SCALE_IN_CENTS = 44000;
const GROCERIES_SO_FAR_IN_CENTS = GROCERIES_BY_MONTH.reduce(
    (sum, month) => sum + month.thisYear,
    0,
);

const NET_WORTH_IN_CENTS = 4821000;
const SAVED_THIS_MONTH_IN_CENTS = 64000;
const SAVINGS_GOAL_IN_CENTS = 80000;

const TOP_LABELS = [
    { name: 'Holidays', amountInCents: 32000, share: 100 },
    { name: 'Kids', amountInCents: 21000, share: 66 },
    { name: 'Car', amountInCents: 14500, share: 45 },
] as const;

/**
 * The MCP tools the assistant calls, in order. Tool names are identifiers on
 * the wire, so they are never translated.
 */
const TOOL_CALLS = ['get_widget_sdk', 'create_widget', 'place_widget'] as const;

const MUTED_TEXT = 'text-[#706f6c] dark:text-[#A1A09A]';
const CHIP_CLASSES = cn(
    'border border-[#e3e3e0] bg-[#FDFDFC] dark:border-[#3E3E3A] dark:bg-[#1C1C1A]',
    MUTED_TEXT,
);
const PANEL_DIVIDER = 'border-b border-[#e3e3e0]/70 dark:border-[#3E3E3A]/45';

type Timeline = (step: Step, index?: number) => number;

function easeOutCubic(value: number): number {
    return 1 - Math.pow(1 - value, 3);
}

function narrowMonthNames(locale: string): string[] {
    const format = new Intl.DateTimeFormat(locale, {
        month: 'narrow',
        timeZone: 'UTC',
    });

    return GROCERIES_BY_MONTH.map((_, month) =>
        format.format(new Date(Date.UTC(2000, month, 1))),
    );
}

function Panel({
    className,
    children,
}: {
    className?: string;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col overflow-hidden rounded-xl border border-[#e3e3e0]/70 bg-gradient-to-br from-zinc-50 to-zinc-100 dark:border-[#3E3E3A]/45 dark:from-zinc-900 dark:to-zinc-950',
                className,
            )}
        >
            {children}
        </div>
    );
}

function Tile({
    className,
    style,
    children,
}: {
    className?: string;
    style?: CSSProperties;
    children: ReactNode;
}) {
    return (
        <div
            className={cn(
                'flex min-w-0 flex-col gap-1 rounded-[10px] border border-[#e3e3e0] bg-[#FDFDFC] px-3 py-2.5 sm:px-4 sm:py-3.5 dark:border-[#3E3E3A] dark:bg-[#161615]',
                className,
            )}
            style={style}
        >
            {children}
        </div>
    );
}

/** Phones get the shorter copy; from `sm` up, the full one. */
function Responsive({ short, full }: { short: string; full: string }) {
    return (
        <>
            <span className="sm:hidden">{short}</span>
            <span className="hidden sm:inline">{full}</span>
        </>
    );
}

function Conversation({
    at,
    dashboardName,
    widgetName,
}: {
    at: Timeline;
    dashboardName: string;
    widgetName: string;
}) {
    const toolDetails: Record<(typeof TOOL_CALLS)[number], string> = {
        get_widget_sdk: '',
        create_widget: widgetName,
        place_widget: __(':dashboard · 8 columns', {
            dashboard: dashboardName,
        }),
    };

    return (
        <Panel className="flex-[1_1_320px]">
            <div
                className={cn(
                    'flex flex-wrap items-center gap-2 px-3 py-2.5 sm:px-4 sm:py-3',
                    PANEL_DIVIDER,
                )}
            >
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-md px-2 py-0.5 text-[0.6875rem] font-medium',
                        CHIP_CLASSES,
                    )}
                >
                    <SparklesIcon
                        aria-hidden="true"
                        className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
                    />
                    {__('Whisper Money connected')}
                </span>
                <span className={cn('text-[0.6875rem]', MUTED_TEXT)}>
                    {__('Sample conversation')}
                </span>
            </div>

            <div className="flex flex-col gap-2.5 px-3 pt-3 pb-3.5 sm:gap-3.5 sm:px-4 sm:pt-4 sm:pb-5">
                <p
                    className="max-w-[88%] self-end rounded-[14px_14px_4px_14px] bg-zinc-800 px-3.5 py-2.5 text-sm leading-normal text-[#FDFDFC] will-change-transform dark:bg-[#EDEDEC] dark:text-[#1C1C1A]"
                    style={riseStyle(at(STEPS.request), 10)}
                >
                    <Responsive
                        short={__(
                            'Chart my groceries by month, this year vs last, on :dashboard.',
                            { dashboard: dashboardName },
                        )}
                        full={__(
                            'Add a chart of my groceries by month, this year against last year, to my :dashboard dashboard.',
                            { dashboard: dashboardName },
                        )}
                    />
                </p>

                <div className="flex flex-row flex-wrap gap-1.5 sm:flex-col">
                    {TOOL_CALLS.map((tool, index) => (
                        <span
                            key={tool}
                            className={cn(
                                'inline-flex items-center gap-1.5 self-start rounded-lg px-2 py-1 font-mono text-[0.6875rem] will-change-transform sm:px-2.5 sm:py-1.25 sm:text-xs',
                                CHIP_CLASSES,
                            )}
                            style={riseStyle(at(STEPS.tool, index), 8)}
                        >
                            <CheckIcon
                                aria-hidden="true"
                                className="size-3 shrink-0 text-emerald-600 dark:text-emerald-400"
                                strokeWidth={2.5}
                            />
                            <span className="text-[#1b1b18] dark:text-[#EDEDEC]">
                                {tool}
                            </span>
                            {toolDetails[tool] !== '' && (
                                <span className="hidden sm:inline">
                                    {toolDetails[tool]}
                                </span>
                            )}
                        </span>
                    ))}
                </div>

                <p
                    className="max-w-[92%] text-sm leading-relaxed will-change-transform"
                    style={riseStyle(at(STEPS.reply), 10)}
                >
                    <Responsive
                        short={__(
                            'Done, it is on :dashboard. You spend 10% less than last year.',
                            { dashboard: dashboardName },
                        )}
                        full={__(
                            'Done. “:widget” is on :dashboard, next to your net worth. You are spending 10% less than last year, mostly since March.',
                            { widget: widgetName, dashboard: dashboardName },
                        )}
                    />
                </p>
            </div>
        </Panel>
    );
}

function GroceriesWidget({
    at,
    widgetName,
    amount,
    locale,
}: {
    at: Timeline;
    widgetName: string;
    amount: (valueInCents: number) => string;
    locale: string;
}) {
    const slot = at(STEPS.widgetSlot);
    const badge = at(STEPS.badge);
    // The ring flares with the badge and softens to a quieter outline.
    const ringAlpha = 0.55 * badge * (1 - at(STEPS.ringSoftens)) + 0.25 * badge;
    const thisYear = new Date().getFullYear();
    const monthNames = narrowMonthNames(locale);

    return (
        <Tile
            className="flex-[2_1_260px] gap-2.5 ring-2 ring-emerald-500/(--ring-alpha) will-change-transform sm:flex-[2_1_400px] dark:ring-emerald-400/(--ring-alpha)"
            style={
                {
                    '--ring-alpha': `${Math.round(ringAlpha * 100)}%`,
                    opacity: slot,
                    transform: `translateY(${(1 - slot) * 14}px) scale(${0.98 + 0.02 * slot})`,
                } as CSSProperties
            }
        >
            <div className="flex flex-wrap items-start justify-between gap-x-2 gap-y-1.5">
                <div className="flex flex-col gap-0.5">
                    <span className="text-[0.8125rem] font-semibold">
                        {widgetName}
                    </span>
                    <span className={cn('text-xs', MUTED_TEXT)}>
                        {__(':amount so far · 10% less than last year', {
                            amount: amount(GROCERIES_SO_FAR_IN_CENTS),
                        })}
                    </span>
                </div>
                <span
                    className="inline-flex items-center gap-1.25 rounded-full bg-emerald-500/12 px-2 py-0.5 text-[0.6875rem] font-medium text-emerald-700 dark:bg-emerald-400/14 dark:text-emerald-300"
                    style={{ opacity: badge }}
                >
                    <SparklesIcon aria-hidden="true" className="size-2.75" />
                    {__('Made by your assistant')}
                </span>
            </div>

            <div className="flex h-22 items-end gap-1.5 border-b border-[#e3e3e0] pt-3 sm:h-32 dark:border-[#3E3E3A]">
                {GROCERIES_BY_MONTH.map((month, index) => {
                    const grown = easeOutCubic(at(STEPS.bar, index));
                    const barHeight = (valueInCents: number) =>
                        `${(valueInCents / GROCERIES_SCALE_IN_CENTS) * grown * 100}%`;

                    return (
                        <div
                            key={index}
                            className="flex h-full min-w-0 flex-1 items-end justify-center gap-0.5"
                        >
                            <div
                                className="max-w-3 flex-1 rounded-t-[3px] bg-zinc-300 dark:bg-zinc-700"
                                style={{ height: barHeight(month.lastYear) }}
                            />
                            <div
                                className="max-w-3 flex-1 rounded-t-[3px] bg-emerald-500"
                                style={{ height: barHeight(month.thisYear) }}
                            />
                        </div>
                    );
                })}
            </div>
            <div className="-mt-1 flex gap-1.5">
                {monthNames.map((monthName, index) => (
                    <span
                        key={index}
                        className={cn(
                            'min-w-0 flex-1 text-center text-[0.625rem]',
                            MUTED_TEXT,
                        )}
                    >
                        {monthName}
                    </span>
                ))}
            </div>
            <div
                className={cn(
                    'flex flex-wrap gap-3.5 text-[0.6875rem]',
                    MUTED_TEXT,
                )}
            >
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-[2px] bg-emerald-500" />
                    {thisYear}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <span className="size-2 rounded-[2px] bg-zinc-300 dark:bg-zinc-700" />
                    {thisYear - 1}
                </span>
            </div>
        </Tile>
    );
}

function Dashboard({
    at,
    dashboardName,
    widgetName,
    amount,
    locale,
}: {
    at: Timeline;
    dashboardName: string;
    widgetName: string;
    amount: (valueInCents: number) => string;
    locale: string;
}) {
    return (
        <Panel className="flex-[999_1_560px]">
            <div
                className={cn(
                    'flex flex-wrap items-center justify-between gap-2 px-3 py-2 sm:px-4 sm:py-2.5',
                    PANEL_DIVIDER,
                )}
            >
                <span className="inline-flex items-center gap-1.5 text-sm font-semibold">
                    {dashboardName}
                    <ChevronDownIcon
                        aria-hidden="true"
                        className={cn('size-3.5', MUTED_TEXT)}
                    />
                </span>
                <span
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-lg px-2.5 py-1 text-xs font-medium',
                        CHIP_CLASSES,
                    )}
                >
                    <PencilIcon aria-hidden="true" className="size-3" />
                    {__('Edit')}
                </span>
            </div>

            <div className="flex flex-wrap gap-2 p-2 sm:gap-2.5 sm:p-3">
                <Tile className="flex-[1_1_130px] sm:flex-[1_1_220px]">
                    <span className={cn('text-xs', MUTED_TEXT)}>
                        {__('Net worth')}
                    </span>
                    <span className="text-lg font-semibold tracking-tight tabular-nums sm:text-[1.375rem]">
                        {amount(NET_WORTH_IN_CENTS)}
                    </span>
                    <span className="text-xs font-medium text-emerald-600 dark:text-emerald-400">
                        {__('+2.1% this month')}
                    </span>
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 200 40"
                        preserveAspectRatio="none"
                        className="mt-0.5 hidden h-9 w-full text-emerald-600 sm:block dark:text-emerald-400"
                    >
                        <path
                            d="M0 32 L20 30 L40 31 L60 26 L80 27 L100 22 L120 23 L140 17 L160 15 L180 11 L200 8"
                            fill="none"
                            stroke="currentColor"
                            strokeWidth={2}
                            vectorEffect="non-scaling-stroke"
                        />
                    </svg>
                </Tile>

                <Tile className="flex-[1_1_130px] sm:flex-[1_1_220px]">
                    <span className={cn('text-xs', MUTED_TEXT)}>
                        {__('Saved this month')}
                    </span>
                    <span className="text-lg font-semibold tracking-tight tabular-nums sm:text-[1.375rem]">
                        {amount(SAVED_THIS_MONTH_IN_CENTS)}
                    </span>
                    <span className={cn('text-xs', MUTED_TEXT)}>
                        {__('of your :amount goal', {
                            amount: amount(SAVINGS_GOAL_IN_CENTS),
                        })}
                    </span>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-zinc-200 sm:mt-2.5 dark:bg-zinc-800">
                        <div
                            className="h-full rounded-full bg-emerald-600 dark:bg-emerald-400"
                            style={{
                                width: `${(SAVED_THIS_MONTH_IN_CENTS / SAVINGS_GOAL_IN_CENTS) * 100}%`,
                            }}
                        />
                    </div>
                </Tile>

                <GroceriesWidget
                    at={at}
                    widgetName={widgetName}
                    amount={amount}
                    locale={locale}
                />

                <Tile className="hidden flex-[1_1_200px] gap-2.5 sm:flex">
                    <span className={cn('text-xs', MUTED_TEXT)}>
                        {__('Top labels · 30 days')}
                    </span>
                    {TOP_LABELS.map((label) => (
                        <div key={label.name} className="flex flex-col gap-1">
                            <div className="flex justify-between gap-2 text-[0.8125rem]">
                                <span>{__(label.name)}</span>
                                <span className="font-medium tabular-nums">
                                    {amount(label.amountInCents)}
                                </span>
                            </div>
                            <div className="h-1 overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-800">
                                <div
                                    className="h-full rounded-full bg-zinc-300 dark:bg-zinc-700"
                                    style={{ width: `${label.share}%` }}
                                />
                            </div>
                        </div>
                    ))}
                </Tile>
            </div>
        </Panel>
    );
}

/**
 * A sample conversation where an assistant writes a widget and places it on a
 * dashboard, which draws itself as the page scrolls. Every step is tied to the
 * scroll progress, so scrolling back plays it in reverse.
 */
export function CustomDashboardsPreview({
    currency,
    locale,
}: {
    currency: string;
    locale: string;
}) {
    const { ref: containerRef, progress: scrollProgress } =
        useScrollProgress<HTMLDivElement>();
    const progress = Math.min(1, scrollProgress / TIMELINE_END);
    const at: Timeline = (step, index = 0) =>
        stepProgress(
            progress,
            step.start + index * (step.stagger ?? 0),
            step.span,
        );

    const amount = (valueInCents: number) =>
        formatCurrency(valueInCents, currency, locale, 0, 0);
    // The name of the dashboard every account starts with.
    const dashboardName = __('Overview');
    const widgetName = __('Groceries, this year vs last');

    return (
        <div
            ref={containerRef}
            role="img"
            aria-label={__(
                'A sample conversation where an assistant builds a groceries chart and places it on the dashboard',
            )}
            className="flex flex-wrap items-stretch gap-2.5 select-none sm:gap-3"
        >
            <Conversation
                at={at}
                dashboardName={dashboardName}
                widgetName={widgetName}
            />
            <Dashboard
                at={at}
                dashboardName={dashboardName}
                widgetName={widgetName}
                amount={amount}
                locale={locale}
            />
        </div>
    );
}

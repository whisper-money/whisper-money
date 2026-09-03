import { index as summariesIndex } from '@/actions/App/Http/Controllers/MonthlySummaryController';
import {
    AchievementCell,
    monthLabel,
} from '@/components/achievements/achievement-cell';
import { AchievementFigure } from '@/components/achievements/achievement-figure';
import { Medal } from '@/components/achievements/medal';
import { RarityTag } from '@/components/achievements/rarity-tag';
import { Skeleton } from '@/components/ui/skeleton';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { useLocale } from '@/hooks/use-locale';
import AppLayout from '@/layouts/app-layout';
import { index } from '@/routes/achievements';
import {
    type AchievementMedal,
    type AchievementTrack,
    type BreadcrumbItem,
} from '@/types';
import { __ } from '@/utils/i18n';
import { Deferred, Head, Link } from '@inertiajs/react';
import { ChevronRightIcon } from 'lucide-react';
import { useState } from 'react';

/**
 * Progress: every milestone the reader's money has crossed, and the months we
 * have closed.
 *
 * Forty-six medals, most of them still to come for most people, is a wall of
 * failure if it is drawn as a grid. So it is drawn as eleven ladders instead:
 * each track runs from its smallest rung to its largest, what has been earned
 * sits on the left and what is still to come trails off to the right. The empty
 * slots read as the road ahead, which is what they are.
 *
 * Two ways in. Tracks is the ladder; Timeline is the same medals dated by when
 * they really happened, which is the point of reconstructing the history at all:
 * this is not a grid of today, it is a financial life spread over years.
 */
type SummaryRow = {
    id: string;
    period: string;
    savings_rate: number | null;
    complete: boolean;
    shared: boolean;
    unread: boolean;
};

interface Props {
    currency: string;
    overview: {
        unlocked: number;
        total: number;
        streak: { months: number; since: string } | null;
        latest: { name: string | null; achieved_on: string } | null;
    };
    tracks: AchievementTrack[];
    summaries?: SummaryRow[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Progress', href: index().url },
];

function Overview({ overview }: Pick<Props, 'overview'>) {
    const locale = useLocale();
    const percent = Math.round((overview.unlocked / overview.total) * 100);

    return (
        <div className="grid grid-cols-1 rounded-lg border sm:grid-cols-3">
            <div className="flex flex-col gap-1.5 border-b p-4 sm:border-r sm:border-b-0">
                <span className="text-xs text-muted-foreground">
                    {__('Unlocked')}
                </span>
                <span className="text-lg leading-6 font-semibold tabular-nums">
                    {overview.unlocked}{' '}
                    <span className="font-normal text-muted-foreground">
                        {__('of :total', { total: overview.total })}
                    </span>
                </span>
                <div className="mt-0.5 h-1.5 overflow-hidden rounded-full bg-muted">
                    <div
                        className="h-full rounded-full bg-primary"
                        style={{ width: `${percent}%` }}
                    />
                </div>
            </div>

            <div className="flex flex-col gap-1.5 border-b p-4 sm:border-r sm:border-b-0">
                <span className="text-xs text-muted-foreground">
                    {__('Saving streak')}
                </span>
                <span className="text-lg leading-6 font-semibold tabular-nums">
                    {overview.streak
                        ? __(':count months', {
                              count: overview.streak.months,
                          })
                        : '—'}
                </span>
                <span className="text-xs text-muted-foreground">
                    {overview.streak
                        ? __('Since :month', {
                              month: monthLabel(overview.streak.since, locale),
                          })
                        : __('Starts with your first saving month')}
                </span>
            </div>

            <div className="flex flex-col gap-1.5 p-4">
                <span className="text-xs text-muted-foreground">
                    {__('Latest')}
                </span>
                <span className="text-lg leading-6 font-semibold text-balance">
                    {overview.latest?.name ?? __('Nothing yet')}
                </span>
                <span className="text-xs text-muted-foreground">
                    {overview.latest
                        ? monthLabel(overview.latest.achieved_on, locale)
                        : __('Checked once a day')}
                </span>
            </div>
        </div>
    );
}

function Tracks({ tracks }: { tracks: AchievementTrack[] }) {
    return (
        <div className="divide-y rounded-lg border">
            {tracks.map((track) => (
                <div
                    key={track.key}
                    className="grid items-start gap-3 p-4 sm:grid-cols-[7rem_minmax(0,1fr)]"
                >
                    <div className="flex flex-col gap-0.5 pt-0.5">
                        <div className="flex items-baseline justify-between gap-2 sm:flex-col sm:gap-0.5">
                            <span className="text-sm font-medium">
                                {track.label}
                            </span>
                            <span className="text-xs text-muted-foreground tabular-nums">
                                {__(':unlocked of :total', {
                                    unlocked: track.unlocked,
                                    total: track.medals.length,
                                })}
                            </span>
                        </div>
                        {track.note && (
                            <p className="font-heading mt-1 text-xs text-pretty text-muted-foreground">
                                {track.note}
                            </p>
                        )}
                    </div>

                    {/* Sideways on a phone: the ladder keeps its direction
                        rather than wrapping into an unreadable block. */}
                    <div className="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:overflow-visible sm:px-0">
                        <div className="flex gap-2 sm:flex-wrap">
                            {track.medals.map((medal) => (
                                <AchievementCell
                                    key={medal.key}
                                    medal={medal}
                                />
                            ))}
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}

function Timeline({ tracks }: { tracks: AchievementTrack[] }) {
    const locale = useLocale();

    const earned = tracks
        .flatMap((track) => track.medals)
        .filter((medal): medal is AchievementMedal & { achieved_on: string } =>
            Boolean(medal.achieved_on),
        )
        .sort((a, b) => b.achieved_on.localeCompare(a.achieved_on));

    if (earned.length === 0) {
        return (
            <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                {__('Nothing unlocked yet.')}
            </p>
        );
    }

    const years = [
        ...new Set(earned.map((medal) => medal.achieved_on.slice(0, 4))),
    ];

    return (
        <div className="flex flex-col gap-6">
            {years.map((year) => {
                const medals = earned.filter((medal) =>
                    medal.achieved_on.startsWith(year),
                );

                return (
                    <div
                        key={year}
                        className="grid gap-4 sm:grid-cols-[6rem_minmax(0,1fr)]"
                    >
                        <div className="flex flex-col gap-0.5 sm:pt-3">
                            <span className="text-xl font-semibold tracking-tight tabular-nums">
                                {year}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {__(':count unlocked', {
                                    count: medals.length,
                                })}
                            </span>
                        </div>

                        <div className="divide-y rounded-lg border">
                            {medals.map((medal) => (
                                <div
                                    key={medal.key}
                                    className="flex flex-wrap items-center gap-3 p-3 sm:flex-nowrap sm:gap-4 sm:px-4"
                                >
                                    <Medal
                                        rarity={medal.rarity}
                                        icon={medal.icon}
                                        size={32}
                                    />
                                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <span className="flex flex-wrap items-baseline gap-x-1.5 text-sm font-medium">
                                            {medal.name}
                                            <AchievementFigure
                                                figure={medal.figure}
                                            />
                                        </span>
                                        <RarityTag
                                            rarity={medal.rarity}
                                            share={medal.share}
                                        />
                                    </div>
                                    <div className="ml-auto flex items-center gap-4 text-right sm:ml-0">
                                        <AchievementFigure
                                            figure={medal.reached}
                                            className="text-[13px] text-muted-foreground"
                                        />
                                        <span className="w-18 text-xs text-muted-foreground tabular-nums">
                                            {monthLabel(
                                                medal.achieved_on,
                                                locale,
                                            )}
                                        </span>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

function Summaries({ summaries }: { summaries: SummaryRow[] }) {
    const locale = useLocale();

    if (summaries.length === 0) {
        return (
            <p className="rounded-lg border border-dashed p-8 text-center text-sm text-muted-foreground">
                {__(
                    'Nothing here yet. Your first summary arrives a few days after your first full month.',
                )}
            </p>
        );
    }

    return (
        <ul className="divide-y rounded-lg border">
            {summaries.map((summary) => (
                <li key={summary.id}>
                    <Link
                        href={`/summaries/${summary.id}`}
                        className="flex items-center gap-4 p-4 transition-colors hover:bg-accent"
                    >
                        <div className="flex flex-1 flex-col gap-0.5">
                            <span className="flex items-center gap-2 font-medium capitalize">
                                {monthLabel(`${summary.period}-01`, locale)}
                                {summary.unread && (
                                    <span className="size-1.5 rounded-full bg-foreground" />
                                )}
                            </span>
                            <span className="text-sm text-muted-foreground">
                                {summary.savings_rate === null
                                    ? __('Summary')
                                    : __(':rate% saved', {
                                          rate: summary.savings_rate,
                                      })}
                                {!summary.complete &&
                                    ` · ${__('partial month')}`}
                                {summary.shared && ` · ${__('shared')}`}
                            </span>
                        </div>
                        <ChevronRightIcon className="size-4 text-muted-foreground" />
                    </Link>
                </li>
            ))}
        </ul>
    );
}

export default function AchievementsIndex({
    overview,
    tracks,
    summaries,
}: Props) {
    const [view, setView] = useState<'tracks' | 'timeline'>('tracks');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={__('Progress')} />

            <div className="mx-auto flex w-full max-w-page flex-col gap-6 p-4">
                <div className="flex flex-col gap-1">
                    <h1 className="text-xl font-semibold tracking-tight">
                        {__('Progress')}
                    </h1>
                    <p className="text-sm text-pretty text-muted-foreground">
                        {__(
                            'Every milestone your money has crossed, dated when it actually happened, and the months we have closed.',
                        )}
                    </p>
                </div>

                <Overview overview={overview} />

                <div className="flex flex-col gap-4">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">
                            {__('Achievements')}
                        </h2>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={view}
                            onValueChange={(next) =>
                                next && setView(next as 'tracks' | 'timeline')
                            }
                        >
                            <ToggleGroupItem
                                value="tracks"
                                className="cursor-pointer px-2.5 text-xs aria-checked:bg-primary/10"
                            >
                                {__('Tracks')}
                            </ToggleGroupItem>
                            <ToggleGroupItem
                                value="timeline"
                                className="cursor-pointer px-2.5 text-xs aria-checked:bg-primary/10"
                            >
                                {__('Timeline')}
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>

                    {overview.unlocked === 0 && (
                        <p className="mx-auto max-w-160 rounded-lg border border-dashed px-8 py-7 text-center text-sm text-pretty text-muted-foreground">
                            {__(
                                'Nothing unlocked yet. Achievements are checked once a day: the first ones land as soon as you record a transaction or connect a bank, and anything you crossed before today unlocks with its real date.',
                            )}
                        </p>
                    )}

                    {view === 'tracks' ? (
                        <Tracks tracks={tracks} />
                    ) : (
                        <Timeline tracks={tracks} />
                    )}
                </div>

                <div className="flex flex-col gap-4">
                    <div className="flex items-center justify-between gap-3">
                        <h2 className="text-lg font-semibold">
                            {__('Monthly summaries')}
                        </h2>
                        <Link
                            href={summariesIndex().url}
                            className="text-[13px] font-medium hover:underline"
                        >
                            {__('All summaries')}
                        </Link>
                    </div>

                    <Deferred
                        data="summaries"
                        fallback={<Skeleton className="h-40 rounded-lg" />}
                    >
                        <Summaries summaries={summaries ?? []} />
                    </Deferred>
                </div>
            </div>
        </AppLayout>
    );
}

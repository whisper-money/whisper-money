import { AvailableCard } from '@/components/cashflow/available-card';
import { ExpensesCard } from '@/components/cashflow/expenses-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { SavedInvestedCard } from '@/components/cashflow/saved-invested-card';
import HeadingSmall from '@/components/heading-small';
import { __ } from '@/utils/i18n';
import { AppFrame } from '../../system/app-frame';
import { Camera, type CameraShot } from '../../system/camera';
import {
    durationOf,
    FeatureVideo,
    type FeatureVideoProps,
    type Scene,
} from '../../system/feature-video';
import { countUp } from '../../system/fixtures';
import { type VideoLocale } from '../../system/locale';
import { Reveal, useProgress } from '../../system/motion';
import { Callout, Caption, type CalloutTarget } from '../../system/overlays';
import { seconds } from '../../system/theme';
import { OutroCard, TitleCard } from '../../system/title-card';
import { CURRENT_MONTH, PREVIOUS_MONTH, SHOWN_MONTH } from './fixtures';

interface Line {
    title: string;
    description: string;
}

/** The video's own copy; card names come from the app's translations. */
const COPY: Record<
    VideoLocale,
    {
        currency: string;
        eyebrow: string;
        title: string;
        subtitle: string;
        income: Line;
        available: string;
        split: string;
        overview: string;
        outro: string;
    }
> = {
    es: {
        currency: 'EUR',
        eyebrow: 'Nuevo en Flujo de Caja',
        title: 'Cuánto te queda de verdad',
        subtitle:
            'Tus ingresos, menos tus gastos y lo que ahorras e inviertes.',
        income: {
            title: 'Lo que entra y lo que sale',
            description: 'Tus ingresos y gastos del mes',
        },
        available: 'Lo que te queda tras gastar, ahorrar e invertir',
        split: 'Cuánto de lo apartado ahorras y cuánto inviertes',
        overview: 'Todo en tu flujo de caja',
        outro: 'Ya disponible para todos',
    },
    en: {
        currency: 'USD',
        eyebrow: 'New in Cashflow',
        title: "See what's really left",
        subtitle: 'Your income, minus what you spend, save and invest.',
        income: {
            title: 'What comes in and goes out',
            description: "Your month's income and expenses",
        },
        available: "What's left after spending, saving and investing",
        split: 'How what you set aside is split',
        overview: 'All in your cashflow',
        outro: 'Now available to everyone',
    },
};

/** The app window, laid out at a fixed size so shots can aim at its cards. */
const FRAME = { width: 1760, height: 580 };
/**
 * Measured off a still of the frame above: when the cashflow cards change
 * size, re-measure or the shots and callouts drift off them.
 */
const CARD = { top: 152, width: 410, height: 384, gap: 24, inset: 24 };

/** Grid columns, as the cashflow page lays the summary cards out. */
const COLUMN = { net: 0, savedInvested: 1, available: 2 } as const;

/** Where the card in a grid column sits, in the camera's coordinates. */
function cardAt(column: number): CalloutTarget {
    return {
        x: CARD.inset + column * (CARD.width + CARD.gap),
        y: CARD.top,
        width: CARD.width,
        height: CARD.height,
    };
}

/** A shot that frames one card, zoomed in. */
function onCard(frame: number, column: number): CameraShot {
    const card = cardAt(column);

    return {
        frame,
        x: card.x + card.width / 2,
        // Leaves room under the card for the caption.
        y: card.y + card.height / 2 + 70,
        scale: 1.9,
    };
}

const WIDE = { x: FRAME.width / 2, y: FRAME.height / 2, scale: 1.05 };

type BeatKey = 'income' | 'available' | 'split';

/** The cards the camera stops on, in order, and when (scene frames). */
const CARD_BEATS: { key: BeatKey; column: number; from: number; to: number }[] =
    [
        { key: 'income', column: COLUMN.net, from: 80, to: 200 },
        { key: 'available', column: COLUMN.available, from: 220, to: 380 },
        { key: 'split', column: COLUMN.savedInvested, from: 400, to: 520 },
    ];
const OVERVIEW = { from: 545, to: 620 };

const SHOTS: CameraShot[] = [
    { frame: 50, ...WIDE },
    ...CARD_BEATS.flatMap((beat) => [
        onCard(beat.from, beat.column),
        onCard(beat.to - 10, beat.column),
    ]),
    { frame: OVERVIEW.from, ...WIDE },
];

/** A beat's caption; read at render time, once the locale is applied. */
function captionFor(key: BeatKey, locale: VideoLocale): Line {
    const copy = COPY[locale];

    switch (key) {
        case 'income':
            return copy.income;
        case 'available':
            return { title: __('Available'), description: copy.available };
        case 'split':
            return { title: __('Saved & Invested'), description: copy.split };
    }
}

/** The real cashflow page header and summary cards, in dark mode. */
function CashflowPage({ currency }: { currency: string }) {
    // Figures count up as the cards come in, the way the page fills in.
    const progress = useProgress(15, 75);
    const current = countUp(CURRENT_MONTH, progress);
    const previous = countUp(PREVIOUS_MONTH, progress);

    return (
        <AppFrame width={FRAME.width} height={FRAME.height}>
            <div className="flex items-center justify-between gap-4">
                <HeadingSmall
                    title={__('Cashflow')}
                    description={__('Track your income, expenses, and savings')}
                />
                <PeriodNavigation
                    currentDate={SHOWN_MONTH}
                    periodType="month"
                    onDateChange={() => {}}
                    onPeriodTypeChange={() => {}}
                />
            </div>
            <div className="grid grid-cols-4 gap-6">
                <Reveal delay={6} className="grid">
                    <NetCashflowCard
                        current={current}
                        previous={previous}
                        currency={currency}
                    />
                </Reveal>
                <Reveal delay={12} className="grid">
                    <SavedInvestedCard
                        current={current}
                        previous={previous}
                        currency={currency}
                        showContributionSplit
                    />
                </Reveal>
                <Reveal delay={18} className="grid">
                    <AvailableCard
                        current={current}
                        previous={previous}
                        currency={currency}
                    />
                </Reveal>
                <Reveal delay={24} className="grid">
                    <ExpensesCard
                        current={current}
                        currency={currency}
                        onViewDetails={() => {}}
                    />
                </Reveal>
            </div>
        </AppFrame>
    );
}

function CashflowScene({ locale }: { locale: VideoLocale }) {
    const copy = COPY[locale];

    return (
        <>
            <Camera shots={SHOTS}>
                <Reveal distance={60}>
                    <CashflowPage currency={copy.currency} />
                </Reveal>
                {CARD_BEATS.map((beat) => (
                    <Callout
                        key={beat.key}
                        from={beat.from}
                        to={beat.to}
                        target={cardAt(beat.column)}
                    />
                ))}
            </Camera>
            {CARD_BEATS.map((beat) => {
                const caption = captionFor(beat.key, locale);

                return (
                    <Caption
                        key={beat.key}
                        from={beat.from + 10}
                        to={beat.to}
                        title={caption.title}
                    >
                        {caption.description}
                    </Caption>
                );
            })}
            <Caption
                from={OVERVIEW.from + 10}
                to={OVERVIEW.to}
                title={copy.overview}
            />
        </>
    );
}

function scenesFor(locale: VideoLocale): Scene[] {
    const copy = COPY[locale];

    return [
        {
            id: 'title',
            durationInFrames: seconds(3.5),
            content: (
                <TitleCard
                    eyebrow={copy.eyebrow}
                    title={copy.title}
                    subtitle={copy.subtitle}
                />
            ),
        },
        {
            id: 'cashflow',
            durationInFrames: OVERVIEW.to,
            content: <CashflowScene locale={locale} />,
        },
        {
            id: 'outro',
            durationInFrames: seconds(4),
            content: <OutroCard headline={copy.outro} />,
        },
    ];
}

/** Length of the video; the same in every locale. */
export const CASHFLOW_AVAILABLE_DURATION = durationOf(scenesFor('es'));

export function CashflowAvailableVideo({ locale }: FeatureVideoProps) {
    return (
        <FeatureVideo
            locale={locale}
            currency={COPY[locale].currency}
            scenes={scenesFor(locale)}
        />
    );
}

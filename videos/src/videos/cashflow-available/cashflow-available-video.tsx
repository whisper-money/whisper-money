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

const COPY: Record<
    VideoLocale,
    {
        currency: string;
        eyebrow: string;
        title: string;
        subtitle: string;
        income: [string, string];
        available: [string, string];
        split: [string, string];
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
        income: ['Lo que entra y lo que sale', 'Tus ingresos y gastos del mes'],
        available: [
            'Disponible',
            'Lo que te queda tras gastar, ahorrar e invertir',
        ],
        split: [
            'Ahorro e inversión',
            'Cuánto de lo apartado ahorras e inviertes',
        ],
        overview: 'Todo en tu flujo de caja',
        outro: 'Ya disponible para todos',
    },
    en: {
        currency: 'USD',
        eyebrow: 'New in Cashflow',
        title: "See what's really left",
        subtitle: 'Your income, minus what you spend, save and invest.',
        income: [
            'What comes in and goes out',
            "Your month's income and expenses",
        ],
        available: [
            'Available',
            "What's left after spending, saving and investing",
        ],
        split: ['Saved and invested', 'How what you set aside is split'],
        overview: 'All in your cashflow',
        outro: 'Now available to everyone',
    },
};

/** The app window, laid out at a fixed size so shots can aim at its cards. */
const FRAME = { width: 1760, height: 580 };
/** Measured off a still of the frame above. */
const CARD = { top: 152, width: 410, height: 384, gap: 24, inset: 24 };

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

/** Grid columns, as the cashflow page lays the summary cards out. */
const NET = 0;
const SAVED_INVESTED = 1;
const AVAILABLE = 2;

const BEATS = {
    income: [80, 200],
    available: [220, 380],
    split: [400, 520],
    overview: [545, 620],
} as const;

const SHOTS: CameraShot[] = [
    { frame: 50, ...WIDE },
    onCard(BEATS.income[0], NET),
    onCard(BEATS.income[1] - 10, NET),
    onCard(BEATS.available[0], AVAILABLE),
    onCard(BEATS.available[1] - 10, AVAILABLE),
    onCard(BEATS.split[0], SAVED_INVESTED),
    onCard(BEATS.split[1] - 10, SAVED_INVESTED),
    { frame: BEATS.overview[0], ...WIDE },
];

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
                <Callout
                    from={BEATS.income[0]}
                    to={BEATS.income[1]}
                    target={cardAt(NET)}
                />
                <Callout
                    from={BEATS.available[0]}
                    to={BEATS.available[1]}
                    target={cardAt(AVAILABLE)}
                />
                <Callout
                    from={BEATS.split[0]}
                    to={BEATS.split[1]}
                    target={cardAt(SAVED_INVESTED)}
                />
            </Camera>
            <Caption
                from={BEATS.income[0] + 10}
                to={BEATS.income[1]}
                title={copy.income[0]}
            >
                {copy.income[1]}
            </Caption>
            <Caption
                from={BEATS.available[0] + 10}
                to={BEATS.available[1]}
                title={copy.available[0]}
            >
                {copy.available[1]}
            </Caption>
            <Caption
                from={BEATS.split[0] + 10}
                to={BEATS.split[1]}
                title={copy.split[0]}
            >
                {copy.split[1]}
            </Caption>
            <Caption
                from={BEATS.overview[0] + 10}
                to={BEATS.overview[1]}
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
            durationInFrames: BEATS.overview[1],
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

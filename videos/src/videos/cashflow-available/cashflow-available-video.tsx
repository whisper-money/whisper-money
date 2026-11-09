import { AvailableCard } from '@/components/cashflow/available-card';
import { ExpensesCard } from '@/components/cashflow/expenses-card';
import { NetCashflowCard } from '@/components/cashflow/net-cashflow-card';
import { PeriodNavigation } from '@/components/cashflow/period-navigation';
import { SavedInvestedCard } from '@/components/cashflow/saved-invested-card';
import { SankeyChart } from '@/components/charts/sankey-chart';
import HeadingSmall from '@/components/heading-small';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { type CashflowSummary } from '@/hooks/use-cashflow-data';
import { __ } from '@/utils/i18n';
import { type ReactNode } from 'react';
import { AppFrame } from '../../system/app-frame';
import { Camera } from '../../system/camera';
import { Choreography, type RevealStep } from '../../system/choreography';
import {
    durationOf,
    FeatureVideo,
    type FeatureVideoProps,
    type Scene,
} from '../../system/feature-video';
import { type VideoLocale } from '../../system/locale';
import { Reveal, useCountUp } from '../../system/motion';
import { Caption } from '../../system/overlays';
import { spotlightScene } from '../../system/spotlight';
import { seconds } from '../../system/theme';
import { OutroCard, TitleCard } from '../../system/title-card';
import {
    CURRENT_MONTH,
    moneyFlowFor,
    PREVIOUS_MONTH,
    SHOWN_MONTH,
} from './fixtures';

interface Copy {
    currency: string;
    eyebrow: string;
    title: string;
    subtitle: string;
    available: string;
    free: string;
    split: string;
    splitNote: string;
    expenses: string;
    moneyFlowTitle: string;
    moneyFlow: string;
    overview: string;
    outro: string;
}

/** The video's own copy; card names come from the app's translations. */
const COPY: Record<VideoLocale, Copy> = {
    es: {
        currency: 'EUR',
        eyebrow: 'Nuevo en Flujo de Caja',
        title: 'Cuánto te queda de verdad',
        subtitle:
            'Tus ingresos, menos tus gastos y lo que ahorras e inviertes.',
        available: 'Lo que te queda tras gastar, ahorrar e invertir',
        free: 'Y lo libre, tras tus presupuestos',
        split: 'Cuánto ahorras y cuánto inviertes',
        splitNote: '60 % ahorrado · 40 % invertido',
        expenses: 'Fijos, variables y el resto',
        // The app's own "Money Flow" reads "Flujo de Caja" in Spanish, the
        // same as the page: the headline names the diagram instead.
        moneyFlowTitle: 'Flujo del dinero',
        moneyFlow: 'De dónde viene y adónde va',
        overview: 'Todo en tu flujo de caja',
        outro: 'Ya disponible para todos',
    },
    en: {
        currency: 'USD',
        eyebrow: 'New in Cashflow',
        title: "See what's really left",
        subtitle: 'Your income, minus what you spend, save and invest.',
        available: "What's left after spending, saving and investing",
        free: "And what's free after budgets",
        split: 'How much you save and how much you invest',
        splitNote: '60% saved · 40% invested',
        expenses: 'Fixed, variable and the rest',
        moneyFlowTitle: 'Money Flow',
        moneyFlow: 'Where it comes from and where it goes',
        overview: 'All in your cashflow',
        outro: 'Now available to everyone',
    },
};

/** Frame the figures start counting up at, in each spotlight. */
const COUNT_FROM = 10;
/** Width the summary cards lay out at in a spotlight, close to the page's. */
const CARD_WIDTH = 440;
const CARD_ZOOM = 1.6;

const HEADER: RevealStep = { selector: '[data-slot=card-header]', at: 4 };
const CONTENT = '[data-slot=card-content]';

interface SummaryProps {
    current: CashflowSummary;
    previous: CashflowSummary;
    currency: string;
}

/** A summary card on its own, its figures counting up as its parts come in. */
function CountingCard({
    currency,
    steps,
    render,
}: {
    currency: string;
    steps: RevealStep[];
    render: (props: SummaryProps) => ReactNode;
}) {
    const current = useCountUp(CURRENT_MONTH, COUNT_FROM);
    const previous = useCountUp(PREVIOUS_MONTH, COUNT_FROM);

    return (
        <Choreography steps={steps}>
            {render({ current, previous, currency })}
        </Choreography>
    );
}

/** The Money Flow card as the cashflow page shows it, flowing in from the left. */
function MoneyFlowCard({
    locale,
    currency,
}: {
    locale: VideoLocale;
    currency: string;
}) {
    return (
        <Choreography
            steps={[
                HEADER,
                { selector: '.recharts-wrapper', at: 10, effect: 'wipe' },
            ]}
        >
            <Card>
                <CardHeader className="pb-4">
                    <CardTitle className="text-base">
                        {__('Money Flow')}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <SankeyChart
                        data={moneyFlowFor(locale)}
                        height={400}
                        currency={currency}
                        centerLabel={__('Available')}
                    />
                </CardContent>
            </Card>
        </Choreography>
    );
}

/** The app window, laid out at a fixed size for the closing wide shot. */
const FRAME = { width: 1760, height: 580 };
const OVERVIEW_FRAMES = seconds(3.5);

/** Everything together: the cashflow page header and its summary cards. */
function OverviewScene({ copy }: { copy: Copy }) {
    const props: SummaryProps = {
        current: CURRENT_MONTH,
        previous: PREVIOUS_MONTH,
        currency: copy.currency,
    };
    const center = { x: FRAME.width / 2, y: FRAME.height / 2 };

    return (
        <>
            <Camera
                shots={[
                    { frame: 0, ...center, scale: 0.98 },
                    { frame: OVERVIEW_FRAMES, ...center, scale: 1.06 },
                ]}
            >
                <AppFrame width={FRAME.width} height={FRAME.height}>
                    <div className="flex items-center justify-between gap-4">
                        <HeadingSmall
                            title={__('Cashflow')}
                            description={__(
                                'Track your income, expenses, and savings',
                            )}
                        />
                        <PeriodNavigation
                            currentDate={SHOWN_MONTH}
                            periodType="month"
                            onDateChange={() => {}}
                            onPeriodTypeChange={() => {}}
                        />
                    </div>
                    <div className="grid grid-cols-4 gap-6">
                        <Reveal className="grid">
                            <NetCashflowCard {...props} />
                        </Reveal>
                        <Reveal delay={4} className="grid">
                            <SavedInvestedCard
                                {...props}
                                showContributionSplit
                            />
                        </Reveal>
                        <Reveal delay={8} className="grid">
                            <AvailableCard {...props} />
                        </Reveal>
                        <Reveal delay={12} className="grid">
                            <ExpensesCard {...props} onViewDetails={() => {}} />
                        </Reveal>
                    </div>
                </AppFrame>
            </Camera>
            <Caption from={14} to={OVERVIEW_FRAMES} title={copy.overview} />
        </>
    );
}

/** Built inside <FeatureVideo>, so `__()` already answers in `locale`. */
function scenesFor(locale: VideoLocale): Scene[] {
    const copy = COPY[locale];
    const currency = copy.currency;

    return [
        {
            id: 'title',
            durationInFrames: seconds(3),
            content: (
                <TitleCard
                    eyebrow={copy.eyebrow}
                    title={copy.title}
                    subtitle={copy.subtitle}
                />
            ),
        },
        spotlightScene({
            id: 'available',
            durationInFrames: seconds(5.5),
            title: __('Available'),
            subtitle: copy.available,
            note: { at: 80, text: copy.free },
            width: CARD_WIDTH,
            zoom: CARD_ZOOM,
            content: (
                <CountingCard
                    currency={currency}
                    steps={[
                        HEADER,
                        { selector: `${CONTENT} > *`, at: 12, stagger: 10 },
                        {
                            selector: `${CONTENT} > .grid > *`,
                            at: 34,
                            stagger: 8,
                        },
                        {
                            selector: `${CONTENT} > :last-child`,
                            at: 62,
                            effect: 'pop',
                        },
                    ]}
                    render={(props) => <AvailableCard {...props} />}
                />
            ),
        }),
        spotlightScene({
            id: 'saved-invested',
            durationInFrames: seconds(4.5),
            title: __('Saved & Invested'),
            subtitle: copy.split,
            note: { at: 62, text: copy.splitNote },
            width: CARD_WIDTH,
            zoom: CARD_ZOOM,
            content: (
                <CountingCard
                    currency={currency}
                    steps={[
                        HEADER,
                        { selector: `${CONTENT} > *`, at: 10, stagger: 8 },
                        {
                            selector: `${CONTENT} > [role=img]`,
                            at: 36,
                            effect: 'wipe',
                        },
                        {
                            selector: `${CONTENT} span.rounded-full`,
                            at: 48,
                            effect: 'pop',
                            stagger: 6,
                        },
                    ]}
                    render={(props) => (
                        <SavedInvestedCard {...props} showContributionSplit />
                    )}
                />
            ),
        }),
        spotlightScene({
            id: 'expenses',
            durationInFrames: seconds(4.5),
            title: __('Expenses'),
            subtitle: copy.expenses,
            width: CARD_WIDTH,
            zoom: CARD_ZOOM,
            content: (
                <CountingCard
                    currency={currency}
                    steps={[
                        HEADER,
                        { selector: `${CONTENT} > *`, at: 10, stagger: 8 },
                        {
                            selector: `${CONTENT} [role=img]`,
                            at: 20,
                            effect: 'wipe',
                        },
                        { selector: 'li', at: 28, stagger: 7 },
                        { selector: '[data-slot=card-footer]', at: 52 },
                    ]}
                    render={({ current, currency: code }) => (
                        <ExpensesCard
                            current={current}
                            currency={code}
                            onViewDetails={() => {}}
                        />
                    )}
                />
            ),
        }),
        spotlightScene({
            id: 'money-flow',
            durationInFrames: seconds(5),
            title: copy.moneyFlowTitle,
            subtitle: copy.moneyFlow,
            width: 1100,
            zoom: 1.35,
            content: <MoneyFlowCard locale={locale} currency={currency} />,
        }),
        {
            id: 'overview',
            durationInFrames: OVERVIEW_FRAMES,
            content: <OverviewScene copy={copy} />,
        },
        {
            id: 'outro',
            durationInFrames: seconds(3.5),
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
            scenes={() => scenesFor(locale)}
        />
    );
}

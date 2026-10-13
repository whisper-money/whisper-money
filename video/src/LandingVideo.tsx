import { fade } from '@remotion/transitions/fade';
import { linearTiming, TransitionSeries } from '@remotion/transitions';
import { type ComponentType, Fragment } from 'react';
import { AbsoluteFill } from 'remotion';
import { Background } from './components/Background';
import { Soundtrack } from './components/Soundtrack';
import { VideoContext } from './context';
import { copy, type Locale } from './copy';
import { Budgets } from './scenes/Budgets';
import { Comparison } from './scenes/Comparison';
import { Cta } from './scenes/Cta';
import { Dashboard } from './scenes/Dashboard';
import { Hook } from './scenes/Hook';
import { NoTracking } from './scenes/NoTracking';
import { Privacy } from './scenes/Privacy';
import { Spreadsheet } from './scenes/Spreadsheet';
import { Sync } from './scenes/Sync';
import { Turn } from './scenes/Turn';
import { themes, type ThemeName } from './theme';
import { timeline, TRANSITION_FRAMES } from './timeline';
import { type SceneId } from './voiceover';

export type LandingVideoProps = { locale: Locale; theme: ThemeName };

const SCENES: Record<SceneId, ComponentType> = {
    hook: Hook,
    noTracking: NoTracking,
    spreadsheet: Spreadsheet,
    turn: Turn,
    sync: Sync,
    dashboard: Dashboard,
    budgets: Budgets,
    privacy: Privacy,
    comparison: Comparison,
    cta: Cta,
};

export function LandingVideo({ locale, theme }: LandingVideoProps) {
    const videoTimeline = timeline(locale);

    return (
        <VideoContext.Provider value={{ theme: themes[theme], copy: copy[locale], locale }}>
            <AbsoluteFill>
                <Background />
                <TransitionSeries>
                    {videoTimeline.scenes.map(({ id, durationInFrames }, index) => {
                        const Scene = SCENES[id];

                        return (
                            <Fragment key={id}>
                                {index > 0 && (
                                    <TransitionSeries.Transition
                                        presentation={fade()}
                                        timing={linearTiming({ durationInFrames: TRANSITION_FRAMES })}
                                    />
                                )}
                                <TransitionSeries.Sequence durationInFrames={durationInFrames}>
                                    <Scene />
                                </TransitionSeries.Sequence>
                            </Fragment>
                        );
                    })}
                </TransitionSeries>
                <Soundtrack locale={locale} timeline={videoTimeline} />
            </AbsoluteFill>
        </VideoContext.Provider>
    );
}

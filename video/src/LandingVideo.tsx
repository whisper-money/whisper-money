import { fade } from '@remotion/transitions/fade';
import { linearTiming, TransitionSeries } from '@remotion/transitions';
import { type ComponentType, Fragment } from 'react';
import { AbsoluteFill } from 'remotion';
import { Background } from './components/Background';
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

export type LandingVideoProps = { locale: Locale; theme: ThemeName };

const TRANSITION_FRAMES = 12;

const SCENES: { component: ComponentType; durationInFrames: number }[] = [
    { component: Hook, durationInFrames: 90 },
    { component: NoTracking, durationInFrames: 110 },
    { component: Spreadsheet, durationInFrames: 170 },
    { component: Turn, durationInFrames: 75 },
    { component: Sync, durationInFrames: 165 },
    { component: Dashboard, durationInFrames: 150 },
    { component: Budgets, durationInFrames: 150 },
    { component: Privacy, durationInFrames: 110 },
    { component: Comparison, durationInFrames: 165 },
    { component: Cta, durationInFrames: 130 },
];

export const LANDING_VIDEO_DURATION =
    SCENES.reduce((total, scene) => total + scene.durationInFrames, 0) - TRANSITION_FRAMES * (SCENES.length - 1);

export function LandingVideo({ locale, theme }: LandingVideoProps) {
    return (
        <VideoContext.Provider value={{ theme: themes[theme], copy: copy[locale] }}>
            <AbsoluteFill>
                <Background />
                <TransitionSeries>
                    {SCENES.map(({ component: Scene, durationInFrames }, index) => (
                        <Fragment key={index}>
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
                    ))}
                </TransitionSeries>
            </AbsoluteFill>
        </VideoContext.Provider>
    );
}

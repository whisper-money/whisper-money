import { Composition } from 'remotion';
import { FPS } from './animation';
import { LandingVideo, type LandingVideoProps } from './LandingVideo';
import { timeline } from './timeline';

export function Root() {
    return (
        <Composition
            id="LandingVideo"
            component={LandingVideo}
            // Replaced per locale by calculateMetadata: each language has its own voice-over length.
            durationInFrames={1}
            calculateMetadata={({ props }) => ({ durationInFrames: timeline(props.locale).durationInFrames })}
            fps={FPS}
            width={1920}
            height={1080}
            defaultProps={{ locale: 'en', theme: 'dark' } satisfies LandingVideoProps}
        />
    );
}

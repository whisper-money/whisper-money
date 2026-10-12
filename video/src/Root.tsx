import { Composition } from 'remotion';
import { FPS } from './animation';
import { LANDING_VIDEO_DURATION, LandingVideo, type LandingVideoProps } from './LandingVideo';

export function Root() {
    return (
        <Composition
            id="LandingVideo"
            component={LandingVideo}
            durationInFrames={LANDING_VIDEO_DURATION}
            fps={FPS}
            width={1920}
            height={1080}
            defaultProps={{ locale: 'en', theme: 'dark' } satisfies LandingVideoProps}
        />
    );
}

/**
 * Feature videos, built with Remotion from the app's real UI components.
 *
 *   bun run video:studio                      preview and scrub every video
 *   bun run video:render <id> videos/out/<file>.mp4
 *
 * A new video lives in src/videos/<feature>/: a component that hands
 * <FeatureVideo> its scenes (TitleCard, an <AppFrame> around real components
 * under a <Camera>, Captions and Callouts, OutroCard), fed made-up figures
 * (system/fixtures.ts; never real user data). Register it below once per
 * locale. App components see a mocked Inertia page (system/inertia-mock.tsx),
 * so pick ones that render from props rather than fetching their own data.
 * Shots aim at fixed coordinates measured off a still: re-measure them when
 * the components a video shows change size.
 *
 * Both scripts run `php artisan wayfinder:generate` first, since app
 * components import the generated routes; run them from the repo root.
 *
 * Music is generated: `node videos/scripts/generate-music.mjs` rewrites
 * public/music/background.wav. Remotion's licence is free for individuals and
 * companies of up to three people; see https://remotion.dev/license.
 */
import { Composition } from 'remotion';
import { type VideoLocale } from './system/locale';
import { VIDEO } from './system/theme';
import {
    CASHFLOW_AVAILABLE_DURATION,
    CashflowAvailableVideo,
} from './videos/cashflow-available/cashflow-available-video';

const LOCALES: VideoLocale[] = ['es', 'en'];

export function RemotionRoot() {
    return (
        <>
            {LOCALES.map((locale) => (
                <Composition
                    key={locale}
                    id={`cashflow-available-${locale}`}
                    component={CashflowAvailableVideo}
                    durationInFrames={CASHFLOW_AVAILABLE_DURATION}
                    defaultProps={{ locale }}
                    {...VIDEO}
                />
            ))}
        </>
    );
}

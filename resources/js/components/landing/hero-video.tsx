import { useDocumentColorScheme } from '@/hooks/use-document-color-scheme';
import { useMediaQuery } from '@/hooks/use-media-query';
import { landingVideoSources } from '@/lib/landing-video';
import { __ } from '@/utils/i18n';
import { useEffect, useRef } from 'react';

/**
 * Plays only while on screen, so a looping video scrolled past does not keep
 * decoding in the background. The observer is what autoplays it: an `autoPlay`
 * attribute would start downloading and playing even when it is off screen.
 */
function LoopingVideo({
    src,
    poster,
    label,
}: {
    src: string;
    poster: string;
    label: string;
}) {
    const videoRef = useRef<HTMLVideoElement>(null);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        // React sets `muted` as a property only, and iOS refuses to autoplay
        // a video it does not see muted before play() is called.
        video.muted = true;

        const observer = new IntersectionObserver(([entry]) => {
            if (entry.isIntersecting) {
                // Rejected when the browser blocks autoplay (e.g. low power
                // mode): the poster stays up, which is the right fallback.
                video.play().catch(() => {});
            } else {
                video.pause();
            }
        });
        observer.observe(video);

        return () => observer.disconnect();
    }, []);

    return (
        <video
            ref={videoRef}
            src={src}
            poster={poster}
            aria-label={label}
            muted
            loop
            playsInline
            preload="metadata"
            className="absolute inset-0 size-full object-cover"
        />
    );
}

/**
 * The product video in the landing hero, in the visitor's language and color
 * scheme.
 *
 * Both posters are server-rendered behind `dark:` variants so the first paint
 * matches the scheme even for "system", which the server cannot know. The
 * `<video>` is attached on the client once the scheme is known, and is keyed
 * by its source: a theme switch swaps it for the other variant, so only one
 * MP4 is ever downloading. Visitors who prefer reduced motion keep the poster.
 */
export function HeroVideo({ language }: { language: string }) {
    const scheme = useDocumentColorScheme();
    const prefersReducedMotion = useMediaQuery(
        '(prefers-reduced-motion: reduce)',
        true,
    );
    const label = __('Whisper Money product video');
    const light = landingVideoSources(language, 'light');
    const dark = landingVideoSources(language, 'dark');
    const activeSources =
        scheme && !prefersReducedMotion
            ? scheme === 'dark'
                ? dark
                : light
            : null;

    return (
        <div className="relative z-10 overflow-hidden rounded-2xl border border-[#e3e3e0]/50 bg-[#FDFDFC]/50 p-2 shadow-2xl dark:border-[#3E3E3A]/10 dark:bg-[#161615]/50">
            <div className="relative overflow-hidden rounded-md border border-[#e3e3e0]/70 shadow-2xl dark:border-[#3E3E3A]/5">
                <div
                    data-slot="hero-video"
                    className="relative aspect-video bg-[#FDFDFC] dark:bg-[#0a0a0a]"
                >
                    <img
                        src={light.poster}
                        alt={activeSources ? '' : label}
                        className="absolute inset-0 size-full object-cover dark:hidden"
                    />
                    <img
                        src={dark.poster}
                        alt={activeSources ? '' : label}
                        className="absolute inset-0 hidden size-full object-cover dark:block"
                    />
                    {activeSources && (
                        <LoopingVideo
                            key={activeSources.video}
                            src={activeSources.video}
                            poster={activeSources.poster}
                            label={label}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

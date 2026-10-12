import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { useDocumentColorScheme } from '@/hooks/use-document-color-scheme';
import { useMediaQuery } from '@/hooks/use-media-query';
import {
    type LandingVideoSources,
    landingVideoSources,
} from '@/lib/landing-video';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { PlayIcon, XIcon } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/**
 * Plays only while on screen and not `paused`, so a looping video scrolled past
 * (or covered by the tour) does not keep decoding in the background. The
 * observer is what autoplays it: an `autoPlay` attribute would start
 * downloading and playing even when it is off screen.
 */
function LoopingVideo({
    src,
    poster,
    paused,
}: {
    src: string;
    poster: string;
    paused: boolean;
}) {
    const videoRef = useRef<HTMLVideoElement>(null);
    const [isOnScreen, setIsOnScreen] = useState(false);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        // React sets `muted` as a property only, and iOS refuses to autoplay
        // a video it does not see muted before play() is called.
        video.muted = true;

        const observer = new IntersectionObserver(([entry]) =>
            setIsOnScreen(entry.isIntersecting),
        );
        observer.observe(video);

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const video = videoRef.current;

        if (!video) {
            return;
        }

        if (isOnScreen && !paused) {
            // Rejected when the browser blocks autoplay (e.g. low power mode):
            // the poster stays up, which is the right fallback.
            video.play().catch(() => {});
        } else {
            video.pause();
        }
    }, [isOnScreen, paused]);

    return (
        <video
            ref={videoRef}
            src={src}
            poster={poster}
            aria-hidden
            muted
            loop
            playsInline
            preload="metadata"
            className="absolute inset-0 size-full object-cover"
        />
    );
}

/**
 * The whole tour, from 0:00, in a near-fullscreen player. It is mounted only
 * while open, so every opening starts from the beginning. Not muted: the
 * visitor asked for it, so it plays its audio once the renders have a track.
 */
function ProductTourDialog({
    open,
    onOpenChange,
    sources,
    ctaHref,
    ctaLabel,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    sources: LandingVideoSources;
    ctaHref: string;
    ctaLabel: string;
}) {
    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                aria-describedby={undefined}
                showCloseButton={false}
                overlayClassName="bg-black/80 backdrop-blur-md"
                className="w-[min(85rem,calc(100vw-2rem),calc((100dvh-10rem)*16/9))] max-w-none gap-4 overflow-visible border-0 bg-transparent p-0 shadow-none sm:max-w-none"
            >
                <DialogTitle className="sr-only">
                    {__('Whisper Money product video')}
                </DialogTitle>
                <div className="flex justify-end">
                    <DialogClose
                        aria-label={__('Close the video')}
                        className="inline-flex size-11 cursor-pointer items-center justify-center rounded-full bg-white/12 text-white transition-colors hover:bg-white/20 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    >
                        <XIcon className="size-5" />
                    </DialogClose>
                </div>
                <div className="aspect-video overflow-hidden rounded-2xl border border-white/10 bg-black shadow-[0_40px_160px_rgba(0,0,0,0.6)] sm:rounded-[20px]">
                    <video
                        data-slot="product-tour-video"
                        src={sources.tour}
                        poster={sources.poster}
                        controls
                        autoPlay
                        playsInline
                        className="size-full"
                    />
                </div>
                <div className="flex justify-end">
                    <Button
                        asChild
                        className="h-12 bg-white px-6 text-base font-semibold text-[#1C1C1A] hover:bg-zinc-100"
                    >
                        <Link href={ctaHref}>{ctaLabel}</Link>
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

/**
 * The product video in the landing hero, in the visitor's language and color
 * scheme, tilted in 3D. The whole card opens the full tour in a dialog.
 *
 * Both posters are server-rendered behind `dark:` variants so the first paint
 * matches the scheme even for "system", which the server cannot know. The
 * looping `<video>` is attached on the client once the scheme is known, and is
 * keyed by its source: a theme switch swaps it for the other variant, so only
 * one MP4 is ever downloading. Visitors who prefer reduced motion keep the
 * poster, and still get the tour when they ask for it.
 */
export function HeroVideo({
    language,
    ctaHref,
    ctaLabel,
}: {
    language: string;
    ctaHref: string;
    ctaLabel: string;
}) {
    const scheme = useDocumentColorScheme();
    const prefersReducedMotion = useMediaQuery(
        '(prefers-reduced-motion: reduce)',
        true,
    );
    const [isTourOpen, setIsTourOpen] = useState(false);
    const light = landingVideoSources(language, 'light');
    const dark = landingVideoSources(language, 'dark');
    const schemeSources = scheme === 'dark' ? dark : light;

    return (
        <div className="relative flex flex-col gap-3.5 perspective-[1400px] lg:perspective-[2000px]">
            <div
                aria-hidden
                className="absolute inset-x-[10%] inset-y-[20%] rounded-full bg-[#1b1b18]/8 blur-[50px] lg:inset-x-[5%] lg:inset-y-[10%] lg:blur-[70px] dark:bg-[#EDEDEC]/10"
            />
            <button
                type="button"
                aria-label={__('Play the product tour')}
                onClick={() => setIsTourOpen(true)}
                className="group relative block w-full [transform:rotateY(-9deg)_rotateX(5deg)_rotateZ(1deg)] cursor-pointer rounded-2xl border border-[#e3e3e0] bg-[#FDFDFC]/60 p-1.5 shadow-[0_24px_60px_rgba(27,27,24,0.16)] focus-visible:ring-2 focus-visible:ring-[#1b1b18] focus-visible:outline-none lg:origin-left lg:[transform:rotateY(-14deg)_rotateX(6deg)_rotateZ(1deg)] lg:rounded-[22px] lg:p-2 lg:shadow-[0_40px_120px_rgba(27,27,24,0.16)] dark:border-[#3E3E3A] dark:bg-[#161615]/60 dark:shadow-[0_24px_60px_rgba(0,0,0,0.6)] dark:focus-visible:ring-[#EDEDEC] lg:dark:shadow-[0_40px_120px_rgba(0,0,0,0.6)]"
            >
                <span
                    data-slot="hero-video"
                    className="relative block aspect-video overflow-hidden rounded-[11px] border border-[#e3e3e0] bg-[#FDFDFC] lg:rounded-[14px] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
                >
                    <img
                        src={light.poster}
                        alt=""
                        className="absolute inset-0 size-full object-cover dark:hidden"
                    />
                    <img
                        src={dark.poster}
                        alt=""
                        className="absolute inset-0 hidden size-full object-cover dark:block"
                    />
                    {scheme && !prefersReducedMotion && (
                        <LoopingVideo
                            key={schemeSources.video}
                            src={schemeSources.video}
                            poster={schemeSources.poster}
                            paused={isTourOpen}
                        />
                    )}
                    <span className="absolute inset-0 flex items-center justify-center lg:hidden">
                        <span className="inline-flex size-15 items-center justify-center rounded-full bg-[#1b1b18]/86 text-white shadow-[0_12px_30px_rgba(0,0,0,0.35)] transition-transform group-hover:scale-105 dark:bg-[#161615]/82 dark:ring-1 dark:ring-white/15">
                            <PlayIcon className="size-5.5 translate-x-0.5 fill-current" />
                        </span>
                    </span>
                    <span className="absolute bottom-5 left-5 hidden items-center gap-3 rounded-full bg-[#1b1b18]/86 py-2 pr-4 pl-2 text-[15px] font-semibold text-white shadow-[0_12px_40px_rgba(0,0,0,0.3)] transition-transform group-hover:scale-105 lg:inline-flex dark:bg-[#161615]/82 dark:ring-1 dark:ring-white/15">
                        <span className="inline-flex size-11 items-center justify-center rounded-full bg-white text-[#1b1b18] dark:text-[#0a0a0a]">
                            <PlayIcon className="size-4.5 translate-x-px fill-current" />
                        </span>
                        {__('See it in 40 seconds')}
                    </span>
                </span>
            </button>
            <p className="relative text-center text-[13px] font-medium text-[#706f6c] lg:hidden dark:text-[#A1A09A]">
                {__('Tap to watch · 0:40')}
            </p>
            <ProductTourDialog
                open={isTourOpen}
                onOpenChange={setIsTourOpen}
                sources={schemeSources}
                ctaHref={ctaHref}
                ctaLabel={ctaLabel}
            />
        </div>
    );
}

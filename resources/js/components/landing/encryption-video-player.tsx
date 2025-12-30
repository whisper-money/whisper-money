import { cn } from '@/lib/utils';
import { RotateCcwIcon } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

interface EncryptionVideoPlayerProps {
    lightSrc: string;
    darkSrc: string;
    className?: string;
}

export default function EncryptionVideoPlayer({
    lightSrc,
    darkSrc,
    className,
}: EncryptionVideoPlayerProps) {
    const lightVideoRef = useRef<HTMLVideoElement>(null);
    const darkVideoRef = useRef<HTMLVideoElement>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const [hasEnded, setHasEnded] = useState(false);
    const [isHovering, setIsHovering] = useState(false);
    const hasPlayedRef = useRef(false);

    const handleReplay = useCallback(() => {
        const lightVideo = lightVideoRef.current;
        const darkVideo = darkVideoRef.current;

        if (lightVideo) {
            lightVideo.currentTime = 0;
            lightVideo.play();
        }
        if (darkVideo) {
            darkVideo.currentTime = 0;
            darkVideo.play();
        }
        setHasEnded(false);
    }, []);

    const handleEnded = useCallback(() => {
        setHasEnded(true);
    }, []);

    useEffect(() => {
        const container = containerRef.current;
        const lightVideo = lightVideoRef.current;
        const darkVideo = darkVideoRef.current;

        if (!container || !lightVideo || !darkVideo) return;

        const observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting && !hasPlayedRef.current) {
                        hasPlayedRef.current = true;
                        lightVideo.play();
                        darkVideo.play();
                    }
                });
            },
            {
                threshold: 0.5,
            },
        );

        observer.observe(container);

        return () => {
            observer.disconnect();
        };
    }, []);

    return (
        <div
            ref={containerRef}
            className={cn('relative', className)}
            onMouseEnter={() => setIsHovering(true)}
            onMouseLeave={() => setIsHovering(false)}
        >
            <div className="rounded-lg border border-border/60 bg-[#FDFDFC] shadow dark:bg-[#0a0a0a]">
                <video
                    ref={lightVideoRef}
                    src={lightSrc}
                    muted
                    playsInline
                    onEnded={handleEnded}
                    className="h-full w-full rounded-lg dark:hidden"
                />
                <video
                    ref={darkVideoRef}
                    src={darkSrc}
                    muted
                    playsInline
                    onEnded={handleEnded}
                    className="hidden h-full w-full rounded-lg dark:block"
                />
            </div>

            {/* Replay button - visible on hover when video has ended */}
            {hasEnded && (
                <button
                    onClick={handleReplay}
                    className={cn(
                        'absolute inset-0 z-20 flex cursor-pointer items-center justify-center rounded-2xl bg-black/30 transition-opacity duration-200 dark:bg-black/50',
                        isHovering ? 'opacity-100' : 'opacity-0',
                    )}
                    aria-label="Replay video"
                >
                    <div className="flex size-16 items-center justify-center rounded-full bg-white/90 shadow-lg transition-transform hover:scale-110 dark:bg-[#161615]/90">
                        <RotateCcwIcon className="size-7 text-[#1b1b18] dark:text-[#EDEDEC]" />
                    </div>
                </button>
            )}
        </div>
    );
}

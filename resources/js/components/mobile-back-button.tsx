import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useWebHaptics } from 'web-haptics/react';

interface Props {
    href: string;
    className?: string;
}

export function MobileBackButton({ href, className }: Props) {
    const [isScrolled, setIsScrolled] = useState(false);
    const { trigger } = useWebHaptics();

    useEffect(() => {
        function handleScroll() {
            setIsScrolled(window.scrollY > 40);
        }

        window.addEventListener('scroll', handleScroll, { passive: true });
        handleScroll();

        return () => window.removeEventListener('scroll', handleScroll);
    }, []);

    function handleBack() {
        trigger('light');
        router.visit(href);
    }

    return (
        <>
            {/* Floating button — visible when scrolled, top-right */}
            <div
                className={cn(
                    'fixed top-4 left-4 z-50 sm:hidden',
                    'transition-all duration-300 ease-in-out',
                    isScrolled
                        ? 'pointer-events-auto translate-y-0 opacity-100'
                        : 'pointer-events-none -translate-y-2 opacity-0',
                )}
            >
                <button
                    onClick={handleBack}
                    aria-label="Go back"
                    className="flex size-11 items-center justify-center rounded-full border border-border/75 bg-sidebar/50 text-primary shadow-lg shadow-black/20 backdrop-blur transition-all duration-200 hover:bg-sidebar/80 active:scale-95"
                >
                    <ArrowLeft className="size-5" />
                </button>
            </div>

            {/* Inline button — shown in header logo slot */}
            <button
                onClick={handleBack}
                aria-label="Go back"
                className={cn(
                    'flex items-center justify-center rounded-md bg-sidebar-primary/95 text-white dark:text-black',
                    'transition-all duration-300 ease-in-out',
                    isScrolled
                        ? 'pointer-events-none scale-75 opacity-0'
                        : 'pointer-events-auto scale-100 opacity-100',
                    'size-7',
                    className,
                )}
            >
                <ArrowLeft className="size-4" />
            </button>
        </>
    );
}

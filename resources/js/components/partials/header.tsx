import { cn, resolveUrl } from '@/lib/utils';
import { dashboard, home, login, roadmap } from '@/routes';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { BirdIcon, Github, LogIn, MapIcon, StarIcon } from 'lucide-react';
import { useEffect, useState, type ReactNode } from 'react';
import DiscordIcon from '../icons/DiscordIcon';
import { Button } from '../ui/button';
import { Separator } from '../ui/separator';

function useGitHubStars(): number | null {
    const [stars, setStars] = useState<number | null>(null);

    useEffect(() => {
        fetch('https://api.github.com/repos/whisper-money/whisper-money')
            .then((res) => res.json())
            .then((data) => {
                if (typeof data.stargazers_count === 'number') {
                    setStars(data.stargazers_count);
                }
            })
            .catch(() => {
                // Silently fail - stars will remain null
            });
    }, []);

    return stars;
}

/**
 * Below `lg` the nav only has room for the icons, so the label stays for
 * screen readers instead of disappearing with the text.
 */
function NavButton({
    label,
    badge,
    children,
}: {
    label: string;
    badge?: ReactNode;
    children: ReactNode;
}) {
    return (
        <Button
            variant={'ghost'}
            className="cursor-pointer px-2 opacity-70 transition-all duration-200 hover:opacity-100 lg:px-4"
        >
            {children}
            <span className="sr-only lg:not-sr-only">{label}</span>
            {badge}
        </Button>
    );
}

/**
 * The logo links back to the landing page, except on the landing page itself.
 */
function Logo({ className }: { className?: string }) {
    const { url } = usePage();
    const isLandingPage = url.split('?')[0] === resolveUrl(home());
    const classes = cn('flex shrink-0 items-center font-mono', className);
    const content = (
        <>
            <BirdIcon className="size-5 text-[#1b1b18] dark:text-[#EDEDEC]" />
            <span className="font-medium whitespace-nowrap">Whisper Money</span>
        </>
    );

    if (isLandingPage) {
        return <div className={classes}>{content}</div>;
    }

    return (
        <Link href={home()} className={classes}>
            {content}
        </Link>
    );
}

type Props = {
    canRegister?: boolean;
    hideExternalButtons?: boolean;
};

export default function Header({
    canRegister = false,
    hideExternalButtons = false,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const stars = useGitHubStars();

    return (
        <>
            {/* Mobile pill header */}
            <header className="fixed top-4 right-4 left-4 z-50 flex items-center justify-between rounded-full border border-border/50 bg-background/70 px-4 py-3.5 shadow-lg shadow-black/10 backdrop-blur-xl sm:hidden dark:border-border/30 dark:shadow-black/30">
                <Logo className="gap-2.5 text-sm" />
                <nav className="flex items-center gap-2">
                    {auth.user ? (
                        <Link href={dashboard()}>
                            <Button
                                size="sm"
                                className="cursor-pointer rounded-full"
                            >
                                {__('Dashboard')}
                            </Button>
                        </Link>
                    ) : (
                        <>
                            <Link href={login()} aria-label={__('Log in')}>
                                <Button
                                    variant={'ghost'}
                                    size="icon-sm"
                                    className="cursor-pointer rounded-full"
                                >
                                    <LogIn className="size-4" />
                                </Button>
                            </Link>
                            {canRegister && (
                                <Link href="/register">
                                    <Button
                                        variant="default"
                                        size="sm"
                                        className="cursor-pointer rounded-full"
                                    >
                                        {__('Register')}
                                    </Button>
                                </Link>
                            )}
                        </>
                    )}
                </nav>
            </header>

            {/* Desktop header */}
            <header className="fixed top-0 z-50 hidden w-full bg-background/5 backdrop-blur-lg sm:block">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4 lg:py-6">
                    <Logo className="gap-2 text-sm lg:gap-4 lg:text-base" />
                    <nav className="flex items-center gap-1 lg:gap-4">
                        {!hideExternalButtons && (
                            <>
                                <Link href={roadmap()}>
                                    <NavButton label={__('Roadmap')}>
                                        <MapIcon className="size-5" />
                                    </NavButton>
                                </Link>
                                <a
                                    href="https://github.com/whisper-money/whisper-money"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <NavButton
                                        label={__('Github')}
                                        badge={
                                            stars !== null && (
                                                <span className="flex items-center gap-1 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium">
                                                    <StarIcon className="size-3 fill-amber-400 text-amber-400" />
                                                    {stars}
                                                </span>
                                            )
                                        }
                                    >
                                        <Github className="size-5" />
                                    </NavButton>
                                </a>
                                <a
                                    href="https://discord.gg/m8hUhx6D9D"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <NavButton label={__('Discord')}>
                                        <DiscordIcon className="size-5" />
                                    </NavButton>
                                </a>
                            </>
                        )}
                        {!hideExternalButtons && (
                            <Separator
                                orientation="vertical"
                                className="hidden data-[orientation=vertical]:h-6 data-[orientation=vertical]:w-[1px] data-[orientation=vertical]:bg-border sm:block"
                            />
                        )}
                        {auth.user ? (
                            <Link href={dashboard()}>
                                <Button className="cursor-pointer">
                                    {__('Dashboard')}
                                </Button>
                            </Link>
                        ) : (
                            <>
                                <Link href={login()}>
                                    <Button
                                        variant={'ghost'}
                                        className="cursor-pointer px-3 lg:px-4"
                                    >
                                        {__('Log in')}
                                    </Button>
                                </Link>
                                {canRegister && (
                                    <Link href="/register">
                                        <Button
                                            variant="default"
                                            className="cursor-pointer"
                                        >
                                            {__('Register')}
                                        </Button>
                                    </Link>
                                )}
                            </>
                        )}
                    </nav>
                </div>
            </header>
        </>
    );
}

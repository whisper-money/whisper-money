import { cn } from '@/lib/utils';
import { dashboard, login } from '@/routes';
import { type SharedData } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, usePage } from '@inertiajs/react';
import { BirdIcon, Github, StarIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
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

type Props = {
    canRegister?: boolean;
    hideAuthButtons?: boolean;
    hideExternalButtons?: boolean;
};

export default function Header({
    canRegister = false,
    hideAuthButtons = false,
    hideExternalButtons = false,
}: Props) {
    const { auth } = usePage<SharedData>().props;
    const stars = useGitHubStars();

    return (
        <>
            {/* Mobile pill header */}
            <header className="fixed top-4 right-4 left-4 z-50 flex items-center justify-between rounded-full border border-border/50 bg-background/70 px-4 py-3.5 shadow-lg shadow-black/10 backdrop-blur-xl sm:hidden dark:border-border/30 dark:shadow-black/30">
                <div className="flex items-center gap-2.5 font-mono">
                    <BirdIcon className="size-4 text-[#1b1b18] dark:text-[#EDEDEC]" />
                    <span className="text-sm font-medium">Whisper Money</span>
                </div>
                <nav className="flex items-center gap-2">
                    {!hideExternalButtons && (
                        <>
                            <a
                                href="https://github.com/whisper-money/whisper-money"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button
                                    variant={'ghost'}
                                    className={cn([
                                        'cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100',
                                        { 'hidden sm:flex': !hideAuthButtons },
                                    ])}
                                >
                                    <Github className="size-5" />
                                    <span className="hidden sm:inline">
                                        {__('Github')}
                                    </span>
                                    {stars !== null && (
                                        <span className="flex items-center gap-1 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium">
                                            <StarIcon className="size-3 fill-amber-400 text-amber-400" />
                                            {stars}
                                        </span>
                                    )}
                                </Button>
                            </a>
                            <a
                                href="https://discord.gg/2WZmDW9QZ8"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button
                                    variant={'ghost'}
                                    className={cn([
                                        'cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100',
                                        { 'hidden sm:flex': !hideAuthButtons },
                                    ])}
                                >
                                    <DiscordIcon className="size-5" />
                                    <span className="hidden sm:inline">
                                        Discord
                                    </span>
                                </Button>
                            </a>
                        </>
                    )}
                    {!hideAuthButtons && !hideExternalButtons && (
                        <Separator
                            orientation="vertical"
                            className={cn([
                                'data-[orientation=vertical]:h-6 data-[orientation=vertical]:w-[1px] data-[orientation=vertical]:bg-border',
                                { 'hidden sm:block': !hideAuthButtons },
                            ])}
                        />
                    )}
                    {!hideAuthButtons && (
                        <>
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
                                    <Link href={login({ query: { force: 1 } })}>
                                        <Button
                                            variant={'ghost'}
                                            size="sm"
                                            className="cursor-pointer rounded-full"
                                        >
                                            {__('Log in')}
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
                        </>
                    )}
                </nav>
            </header>

            {/* Desktop header */}
            <header className="fixed top-0 z-50 hidden w-full bg-background/5 backdrop-blur-lg sm:block">
                <div className="mx-auto flex max-w-6xl items-center justify-between px-6 py-4 lg:py-6">
                    <div className="flex items-center gap-4 font-mono">
                        <BirdIcon className="size-5 text-[#1b1b18] dark:text-[#EDEDEC]" />
                        <span className="font-medium">Whisper Money</span>
                    </div>
                    <nav className="flex items-center gap-4">
                        {!hideExternalButtons && (
                            <>
                                <a
                                    href="https://github.com/whisper-money/whisper-money"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button
                                        variant={'ghost'}
                                        className={cn([
                                            'cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100',
                                            {
                                                'hidden sm:flex':
                                                    !hideAuthButtons,
                                            },
                                        ])}
                                    >
                                        <Github className="size-5" />
                                        <span className="hidden sm:inline">
                                            {__('Github')}
                                        </span>
                                        {stars !== null && (
                                            <span className="flex items-center gap-1 rounded-full bg-muted px-1.5 py-0.5 text-xs font-medium">
                                                <StarIcon className="size-3 fill-amber-400 text-amber-400" />
                                                {stars}
                                            </span>
                                        )}
                                    </Button>
                                </a>
                                <a
                                    href="https://discord.gg/2WZmDW9QZ8"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <Button
                                        variant={'ghost'}
                                        className={cn([
                                            'cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100',
                                            {
                                                'hidden sm:flex':
                                                    !hideAuthButtons,
                                            },
                                        ])}
                                    >
                                        <DiscordIcon className="size-5" />
                                        <span className="hidden sm:inline">
                                            {__('Discord')}
                                        </span>
                                    </Button>
                                </a>
                            </>
                        )}
                        {!hideAuthButtons && !hideExternalButtons && (
                            <Separator
                                orientation="vertical"
                                className={cn([
                                    'data-[orientation=vertical]:h-6 data-[orientation=vertical]:w-[1px] data-[orientation=vertical]:bg-border',
                                    {
                                        'hidden sm:block': !hideAuthButtons,
                                    },
                                ])}
                            />
                        )}
                        {!hideAuthButtons && (
                            <>
                                {auth.user ? (
                                    <Link href={dashboard()}>
                                        <Button className="cursor-pointer">
                                            {__('Dashboard')}
                                        </Button>
                                    </Link>
                                ) : (
                                    <>
                                        <Link
                                            href={login({ query: { force: 1 } })}
                                        >
                                            <Button
                                                variant={'ghost'}
                                                className="cursor-pointer"
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
                            </>
                        )}
                    </nav>
                </div>
            </header>
        </>
    );
}

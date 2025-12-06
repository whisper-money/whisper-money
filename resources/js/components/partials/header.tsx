import { dashboard } from '@/routes';
import { type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { BirdIcon, Github } from 'lucide-react';
import { Button } from '../ui/button';
import { Separator } from '../ui/separator';
import DiscordIcon from '../icons/DiscordIcon';

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

    return (
        <header className="fade-bottom fixed top-0 z-50 w-full bg-background/5 backdrop-blur-lg">
            <div className="mx-auto flex max-w-5xl items-center justify-between px-2 py-4 lg:py-6">
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
                                    className="cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100"
                                >
                                    <Github className="size-5" />
                                    Github
                                </Button>
                            </a>
                            <a
                                href="https://discord.gg/9UQWZECDDv"
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Button
                                    variant={'ghost'}
                                    className="cursor-pointer opacity-70 transition-all duration-200 hover:opacity-100"
                                >
                                    <DiscordIcon className="size-5" />
                                    Discord
                                </Button>
                            </a>
                        </>
                    )}
                    {!hideAuthButtons && (
                        <>
                            <Separator
                                orientation="vertical"
                                className="data-[orientation=vertical]:h-6 data-[orientation=vertical]:w-[1px] data-[orientation=vertical]:bg-border"
                            />
                            {auth.user ? (
                                <Link href={dashboard()}>
                                    <Button className="cursor-pointer">
                                        Dashboard
                                    </Button>
                                </Link>
                            ) : (
                                <>
                                    <Link href="/login">
                                        <Button
                                            variant={'ghost'}
                                            className="cursor-pointer"
                                        >
                                            Log in
                                        </Button>
                                    </Link>
                                    {canRegister && (
                                        <Link href="/register">
                                            <Button
                                                variant="default"
                                                className="cursor-pointer"
                                            >
                                                Register
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
    );
}

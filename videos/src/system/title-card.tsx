import AppLogoIcon from '@/components/app-logo-icon';
import { AbsoluteFill } from 'remotion';
import { Reveal } from './motion';
import { BRAND } from './theme';

/** The app's logo icon on its tile, at a size a 1080p frame can carry. */
function LogoMark({ large = false }: { large?: boolean }) {
    return (
        <div
            className={
                large
                    ? 'flex size-36 items-center justify-center rounded-[2rem] bg-primary'
                    : 'flex size-16 items-center justify-center rounded-2xl bg-primary'
            }
        >
            <AppLogoIcon
                className={
                    large
                        ? 'size-24 dark:text-black'
                        : 'size-10 dark:text-black'
                }
            />
        </div>
    );
}

/** Opening card: the brand, then the feature's name and promise. */
export function TitleCard({
    eyebrow,
    title,
    subtitle,
}: {
    eyebrow: string;
    title: string;
    subtitle?: string;
}) {
    return (
        <AbsoluteFill className="items-center justify-center gap-8 text-center">
            <Reveal className="flex items-center gap-4" distance={20}>
                <LogoMark />
                <span className="font-mono text-3xl font-semibold">
                    Whisper Money
                </span>
            </Reveal>
            <Reveal delay={8}>
                <p className="text-3xl font-medium tracking-widest text-green-400 uppercase">
                    {eyebrow}
                </p>
            </Reveal>
            <Reveal delay={14}>
                <h1 className="max-w-[1500px] text-8xl leading-tight font-semibold tracking-tight">
                    {title}
                </h1>
            </Reveal>
            {subtitle && (
                <Reveal delay={22}>
                    <p className="max-w-[1300px] text-4xl text-muted-foreground">
                        {subtitle}
                    </p>
                </Reveal>
            )}
        </AbsoluteFill>
    );
}

/** Closing card: the logo, the call to action and the site. */
export function OutroCard({ headline }: { headline: string }) {
    return (
        <AbsoluteFill className="items-center justify-center gap-10 text-center">
            <Reveal distance={20}>
                <LogoMark large />
            </Reveal>
            <Reveal delay={8}>
                <h2 className="text-7xl font-semibold tracking-tight">
                    {headline}
                </h2>
            </Reveal>
            <Reveal delay={16}>
                <p className="font-mono text-5xl text-green-400">{BRAND.url}</p>
            </Reveal>
        </AbsoluteFill>
    );
}

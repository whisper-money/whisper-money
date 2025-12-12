import { cn } from '@/lib/utils';
import AppLogoIcon from './app-logo-icon';

export default function AppLogo({ mobile }: { mobile?: boolean }) {
    return (
        <>
            <div
                className={cn([
                    'flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground',
                    { 'size-7 bg-sidebar-primary/95': mobile },
                ])}
            >
                <AppLogoIcon
                    animated
                    className={cn([
                        'size-5 fill-current text-white dark:text-black',
                        { 'size-5': mobile },
                    ])}
                />
            </div>
            {!mobile && (
                <div className="ml-1 grid flex-1 text-left text-sm">
                    <span className="mb-0.5 truncate font-mono leading-tight font-semibold">
                        Whisper Money
                    </span>
                </div>
            )}
        </>
    );
}

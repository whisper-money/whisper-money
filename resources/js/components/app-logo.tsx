import { cn } from '@/lib/utils';
import AppLogoIcon from './app-logo-icon';

export default function AppLogo({ mobile }: { mobile?: boolean }) {
    return (
        <>
            <div className={cn([
                "flex aspect-square size-6 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground",
                { 'size-6 bg-sidebar-primary/95': mobile }
            ])}>
                <AppLogoIcon className={cn([
                    "size-4 fill-current text-white dark:text-black",
                    { 'size-4': mobile }
                ])} />
            </div>
            {!mobile && <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate font-mono leading-tight font-semibold">
                    Whisper Money
                </span>
            </div>}
        </>
    );
}

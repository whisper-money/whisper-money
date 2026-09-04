import DiscordIcon from '@/components/icons/DiscordIcon';
import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { clearKey } from '@/lib/key-storage';
import { logout } from '@/routes';
import accounts from '@/routes/accounts';
import { index as progress } from '@/routes/achievements';
import { edit as editAppearance } from '@/routes/appearance';
import { index as monthlySummaries } from '@/routes/monthly-summaries';
import { type SharedData, type User } from '@/types';
import { __ } from '@/utils/i18n';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Award,
    Eye,
    EyeOff,
    FileText,
    Landmark,
    LifeBuoy,
    LogOut,
    Map,
    MessageSquare,
    Monitor,
    Settings,
} from 'lucide-react';

interface UserMenuContentProps {
    user: User;
    onOpenSupport: () => void;
    onOpenIntegrationRequests: () => void;
}

export function UserMenuContent({
    user,
    onOpenSupport,
    onOpenIntegrationRequests,
}: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { isPrivacyModeEnabled, togglePrivacyMode } = usePrivacyMode();
    const { version, achievements } = usePage<SharedData>().props;

    const handleLogout = () => {
        clearKey();
        cleanup();
        router.flushAll();
    };

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                </div>
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            {/* What already happened: the medals, and the months we closed.
                Not in the main navigation — this is something to look back at,
                not somewhere to work. */}
            <DropdownMenuGroup>
                {achievements && (
                    <DropdownMenuItem asChild>
                        <Link
                            className="block w-full"
                            href={progress()}
                            as="button"
                            prefetch
                            onClick={cleanup}
                        >
                            <Award className="mr-2" />
                            {/* A bare label like every other item: wrapping it
                                in a growing span let the button's inherited
                                `text-align: center` push the text off the icon.
                                The count is pushed right instead. */}
                            {__('Progress')}
                            <span className="ml-auto text-xs text-muted-foreground tabular-nums">
                                {achievements.unlocked} / {achievements.total}
                            </span>
                        </Link>
                    </DropdownMenuItem>
                )}
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full"
                        href={monthlySummaries()}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <FileText className="mr-2" />
                        {__('Monthly summaries')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    onClick={() => {
                        togglePrivacyMode();
                        cleanup();
                    }}
                >
                    {isPrivacyModeEnabled ? (
                        <EyeOff className="mr-2" />
                    ) : (
                        <Eye className="mr-2" />
                    )}
                    {isPrivacyModeEnabled
                        ? __('Disable privacy mode')
                        : __('Enable privacy mode')}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full"
                        href={editAppearance()}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <Monitor className="mr-2" />
                        {__('Appearance')}
                    </Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full"
                        href={accounts.index()}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        {__('Settings')}
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <a
                        className="block w-full cursor-pointer"
                        href="https://discord.gg/m8hUhx6D9D"
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={cleanup}
                    >
                        <DiscordIcon className="mr-2 size-4" />
                        {__('Community')}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a
                        className="block w-full cursor-pointer"
                        href="https://whispermoney.userjot.com/"
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={cleanup}
                    >
                        <MessageSquare className="mr-2" />
                        {__('Feedback')}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <a
                        className="block w-full cursor-pointer"
                        href="https://whispermoney.userjot.com/roadmap"
                        target="_blank"
                        rel="noopener noreferrer"
                        onClick={cleanup}
                    >
                        <Map className="mr-2" />
                        {__('Roadmap')}
                    </a>
                </DropdownMenuItem>
                <DropdownMenuItem
                    onClick={() => {
                        onOpenIntegrationRequests();
                        cleanup();
                    }}
                >
                    <Landmark className="mr-2" />
                    {__('Request integration')}
                </DropdownMenuItem>
                <DropdownMenuItem
                    onClick={() => {
                        onOpenSupport();
                        cleanup();
                    }}
                >
                    <LifeBuoy className="mr-2" />
                    {__('Support')}
                </DropdownMenuItem>
            </DropdownMenuGroup>
            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="block w-full"
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    data-test="logout-button"
                >
                    <LogOut className="mr-2" />
                    {__('Log out')}
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <div className="flex items-center justify-between px-2 py-1.5 text-xs text-muted-foreground">
                <span>{__('Version:')}</span>
                <span>{version}</span>
            </div>
        </>
    );
}

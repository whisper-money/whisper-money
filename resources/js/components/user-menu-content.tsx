import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { isAdmin } from '@/hooks/use-admin';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { clearKey } from '@/lib/key-storage';
import { logout } from '@/routes';
import accounts from '@/routes/accounts';
import { type SharedData, type User } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { Eye, EyeOff, LogOut, Settings } from 'lucide-react';

interface UserMenuContentProps {
    user: User;
}

export function UserMenuContent({ user }: UserMenuContentProps) {
    const cleanup = useMobileNavigation();
    const { isPrivacyModeEnabled, togglePrivacyMode } = usePrivacyMode();
    const { version } = usePage<SharedData>().props;

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
            {isAdmin() && (
                <>
                    <DropdownMenuGroup>
                        <DropdownMenuItem
                            onClick={() => {
                                togglePrivacyMode();
                                cleanup();
                            }}
                        >
                            {isPrivacyModeEnabled ? (
                                <Eye className="mr-2" />
                            ) : (
                                <EyeOff className="mr-2" />
                            )}
                            {isPrivacyModeEnabled
                                ? 'Enable privacy mdoe'
                                : 'Disable privacy mode'}
                        </DropdownMenuItem>
                    </DropdownMenuGroup>
                </>
            )}
            <DropdownMenuGroup>
                <DropdownMenuItem asChild>
                    <Link
                        className="block w-full"
                        href={accounts.index()}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <Settings className="mr-2" />
                        Settings
                    </Link>
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
                    Log out
                </Link>
            </DropdownMenuItem>
            <DropdownMenuSeparator />
            <div className="flex items-center justify-between px-2 py-1.5 text-xs text-muted-foreground">
                <span>Version:</span>
                <span>{version}</span>
            </div>
        </>
    );
}

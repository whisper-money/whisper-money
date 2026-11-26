import {
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuPortal,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
} from '@/components/ui/dropdown-menu';
import { UserInfo } from '@/components/user-info';
import { type User } from '@/types';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import {
    Award,
    CircleHelp,
    Eye,
    EyeOff,
    LogOut,
    Moon,
    Settings,
} from 'lucide-react';
import { Fragment, type ReactNode } from 'react';
import { PlanBadge } from './plan-badge';
import {
    type UserMenuChoiceSet,
    type UserMenuEntry,
    type UserMenuGroup,
    useUserMenuModel,
} from './use-user-menu-model';
import { UserMenuEntryElement, UserMenuEntryMeta } from './user-menu-entry';
import { UserMenuProgress } from './user-menu-progress';
import { UserMenuSwitch } from './user-menu-switch';

const GROUP_LABEL_CLASS =
    'px-2 pt-2 pb-1 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase';

const META_CLASS = 'ml-auto text-xs text-muted-foreground tabular-nums';

function EntryItem({
    entry,
    cleanup,
}: {
    entry: UserMenuEntry;
    cleanup: () => void;
}) {
    const Icon = entry.icon;

    return (
        <DropdownMenuItem asChild>
            <UserMenuEntryElement
                entry={entry}
                onNavigate={cleanup}
                className="w-full cursor-pointer"
            >
                {Icon && <Icon className="size-4" />}
                {entry.label}
                <UserMenuEntryMeta entry={entry} className={META_CLASS} />
            </UserMenuEntryElement>
        </DropdownMenuItem>
    );
}

function Groups({
    groups,
    cleanup,
}: {
    groups: UserMenuGroup[];
    cleanup: () => void;
}) {
    return groups.map((group, index) => (
        <Fragment key={group.id}>
            {index > 0 && <DropdownMenuSeparator />}
            <DropdownMenuGroup>
                {group.title && (
                    <DropdownMenuLabel className={GROUP_LABEL_CLASS}>
                        {group.title}
                    </DropdownMenuLabel>
                )}
                {group.entries.map((entry) => (
                    <EntryItem key={entry.id} entry={entry} cleanup={cleanup} />
                ))}
            </DropdownMenuGroup>
        </Fragment>
    ));
}

function RadioChoices<T extends string>({
    choices,
}: {
    choices: UserMenuChoiceSet<T>;
}) {
    return (
        <>
            <DropdownMenuLabel className={GROUP_LABEL_CLASS}>
                {choices.title}
            </DropdownMenuLabel>
            <DropdownMenuRadioGroup
                value={choices.value}
                onValueChange={(value) => choices.select(value as T)}
            >
                {choices.options.map((option) => (
                    <DropdownMenuRadioItem
                        key={option.value}
                        value={option.value}
                        // A setting: the menu stays open to show the pick.
                        onSelect={(event) => event.preventDefault()}
                    >
                        {option.label}
                    </DropdownMenuRadioItem>
                ))}
            </DropdownMenuRadioGroup>
        </>
    );
}

function Submenu({
    icon,
    label,
    meta,
    children,
}: {
    icon: ReactNode;
    label: string;
    meta?: string;
    children: ReactNode;
}) {
    return (
        <DropdownMenuSub>
            <DropdownMenuSubTrigger className="gap-2 [&_svg:not([class*='size-'])]:size-4 [&_svg:not([class*='text-'])]:text-muted-foreground">
                {icon}
                {label}
                {meta && <span className={META_CLASS}>{meta}</span>}
            </DropdownMenuSubTrigger>
            <DropdownMenuPortal>
                <DropdownMenuSubContent
                    className="w-62 rounded-lg"
                    aria-label={label}
                    sideOffset={8}
                >
                    {children}
                </DropdownMenuSubContent>
            </DropdownMenuPortal>
        </DropdownMenuSub>
    );
}

/**
 * The reorganised user menu (behind the Forecast flag) as a desktop dropdown:
 * the shortcuts people use stay one click away, and settings, appearance and
 * help fold into submenus.
 */
export function UserMenuDropdownContent({
    user,
    onOpenSupport,
    onOpenIntegrationRequests,
}: {
    user: User;
    onOpenSupport: () => void;
    onOpenIntegrationRequests: () => void;
}) {
    const menu = useUserMenuModel({ onOpenSupport, onOpenIntegrationRequests });
    const { cleanup } = menu;

    return (
        <>
            <DropdownMenuLabel className="p-0 font-normal">
                <div className="flex items-center gap-2 px-1 py-1.5 text-left text-sm">
                    <UserInfo user={user} showEmail={true} />
                    {menu.planLabel && (
                        <PlanBadge label={menu.planLabel} isPro={menu.isPro} />
                    )}
                </div>
            </DropdownMenuLabel>

            {menu.achievements && (
                <DropdownMenuItem
                    asChild
                    className="mx-0 my-1 border focus:bg-accent"
                >
                    <Link
                        className="w-full"
                        href={menu.progressHref}
                        as="button"
                        prefetch
                        onClick={cleanup}
                    >
                        <Award className="size-4" />
                        <UserMenuProgress
                            achievements={menu.achievements}
                            barClassName="h-1"
                        />
                    </Link>
                </DropdownMenuItem>
            )}

            <DropdownMenuSeparator />
            <Groups
                groups={[
                    {
                        id: 'organize',
                        title: __('Organize'),
                        entries: menu.organize,
                    },
                ]}
                cleanup={cleanup}
            />

            <DropdownMenuSeparator />
            <DropdownMenuGroup>
                <DropdownMenuItem
                    role="menuitemcheckbox"
                    aria-checked={menu.privacy.enabled}
                    onSelect={(event) => {
                        // A setting, not a destination: the menu stays open
                        // so the switch is seen to move.
                        event.preventDefault();
                        menu.privacy.toggle();
                    }}
                >
                    {menu.privacy.enabled ? <EyeOff /> : <Eye />}
                    {__('Privacy mode')}
                    <UserMenuSwitch checked={menu.privacy.enabled} />
                </DropdownMenuItem>

                <Submenu icon={<Settings />} label={__('Settings')}>
                    <Groups groups={menu.settingsGroups} cleanup={cleanup} />
                </Submenu>

                <Submenu
                    icon={<Moon />}
                    label={__('Appearance')}
                    meta={menu.appearance.label}
                >
                    <RadioChoices choices={menu.appearance} />
                    <DropdownMenuSeparator />
                    <RadioChoices choices={menu.chartScheme} />
                    <DropdownMenuSeparator />
                    <EntryItem entry={menu.moreAppearance} cleanup={cleanup} />
                </Submenu>

                <Submenu icon={<CircleHelp />} label={__('Help & community')}>
                    <Groups groups={menu.helpGroups} cleanup={cleanup} />
                </Submenu>

                {menu.admin && (
                    <EntryItem entry={menu.admin} cleanup={cleanup} />
                )}
            </DropdownMenuGroup>

            <DropdownMenuSeparator />
            <DropdownMenuItem asChild>
                <Link
                    className="w-full"
                    href={menu.logoutRoute}
                    as="button"
                    onClick={menu.onLogout}
                    data-test="logout-button"
                >
                    <LogOut />
                    {__('Log out')}
                </Link>
            </DropdownMenuItem>
            <div className="px-2 pt-1.5 pb-0.5 text-[11px] text-muted-foreground">
                {__('Version :version', { version: menu.version })}
            </div>
        </>
    );
}

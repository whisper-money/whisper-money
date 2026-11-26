import { Card } from '@/components/ui/card';
import {
    Drawer,
    DrawerContent,
    DrawerDescription,
    DrawerTitle,
} from '@/components/ui/drawer';
import { UserInfo } from '@/components/user-info';
import { cn } from '@/lib/utils';
import { type User } from '@/types';
import { __ } from '@/utils/i18n';
import { Link } from '@inertiajs/react';
import { Check, ChevronLeft, ChevronRight } from 'lucide-react';
import { type ReactNode, useState } from 'react';
import { PlanBadge } from './plan-badge';
import {
    type UserMenuChoiceSet,
    type UserMenuEntry,
    type UserMenuGroup,
    type UserMenuLink,
    useUserMenuModel,
} from './use-user-menu-model';
import { UserMenuProgress } from './user-menu-progress';
import { UserMenuSwitch } from './user-menu-switch';

type Level = 'root' | 'settings' | 'appearance' | 'help';

const ROW_CLASS =
    'flex min-h-12 w-full items-center gap-3 px-3.5 text-left text-[15px] text-foreground transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none';

const GROUP_LABEL_CLASS =
    'px-1.5 pb-1.5 text-xs font-semibold tracking-wider text-muted-foreground uppercase';

const META_CLASS = 'ml-auto text-[13px] text-muted-foreground';

/** Rows in one bordered card, a hairline between each. */
function RowCard({ children }: { children: ReactNode }) {
    return (
        <Card size="flush" className="overflow-hidden">
            <div className="divide-y divide-border/60 overflow-hidden rounded-[inherit]">
                {children}
            </div>
        </Card>
    );
}

function EntryRow({
    entry,
    onNavigate,
    className,
    children,
}: {
    entry: UserMenuEntry;
    onNavigate: () => void;
    className?: string;
    children?: ReactNode;
}) {
    const content = children ?? (
        <>
            {entry.label}
            {entry.meta && <span className={META_CLASS}>{entry.meta}</span>}
        </>
    );

    if (entry.kind === 'action') {
        return (
            <button
                type="button"
                className={cn(ROW_CLASS, className)}
                onClick={() => {
                    onNavigate();
                    entry.onSelect();
                }}
            >
                {content}
            </button>
        );
    }

    if (entry.external) {
        return (
            <a
                className={cn(ROW_CLASS, className)}
                href={entry.href}
                target="_blank"
                rel="noopener noreferrer"
                onClick={onNavigate}
            >
                {content}
            </a>
        );
    }

    return (
        <Link
            className={cn(ROW_CLASS, className)}
            href={entry.href}
            prefetch={entry.prefetch ?? true}
            onClick={onNavigate}
        >
            {content}
        </Link>
    );
}

function Tile({
    entry,
    onNavigate,
}: {
    entry: UserMenuLink;
    onNavigate: () => void;
}) {
    const Icon = entry.icon;

    return (
        <Link
            href={entry.href}
            prefetch
            onClick={onNavigate}
            className="flex min-h-19 flex-col items-center justify-center gap-1.5 rounded-xl border bg-card p-1.5 text-center text-xs font-medium text-card-foreground transition-colors hover:bg-accent"
        >
            {Icon && <Icon className="size-5" />}
            {entry.shortLabel ?? entry.label}
        </Link>
    );
}

function GroupCards({
    groups,
    onNavigate,
}: {
    groups: UserMenuGroup[];
    onNavigate: () => void;
}) {
    return groups.map((group) => (
        <div key={group.id}>
            {group.title && (
                <div className={GROUP_LABEL_CLASS}>{group.title}</div>
            )}
            <RowCard>
                {group.entries.map((entry) => (
                    <EntryRow
                        key={entry.id}
                        entry={entry}
                        onNavigate={onNavigate}
                    />
                ))}
            </RowCard>
        </div>
    ));
}

function ChoiceCard<T extends string>({
    choices,
}: {
    choices: UserMenuChoiceSet<T>;
}) {
    return (
        <div role="radiogroup" aria-label={choices.title}>
            <div className={GROUP_LABEL_CLASS}>{choices.title}</div>
            <RowCard>
                {choices.options.map((option) => (
                    <button
                        key={option.value}
                        type="button"
                        role="radio"
                        aria-checked={option.value === choices.value}
                        className={ROW_CLASS}
                        onClick={() => choices.select(option.value)}
                    >
                        {option.label}
                        {option.value === choices.value && (
                            <Check
                                aria-hidden="true"
                                className="ml-auto size-4 text-muted-foreground"
                            />
                        )}
                    </button>
                ))}
            </RowCard>
        </div>
    );
}

/**
 * The reorganised user menu (behind the Forecast flag) on the phone: a bottom
 * sheet instead of a dropdown, whose submenus open as levels with a back
 * button, since floating submenus do not work under a thumb.
 */
export function UserMenuSheet({
    user,
    open,
    onOpenChange,
    onOpenSupport,
    onOpenIntegrationRequests,
}: {
    user: User;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    onOpenSupport: () => void;
    onOpenIntegrationRequests: () => void;
}) {
    const menu = useUserMenuModel({ onOpenSupport, onOpenIntegrationRequests });
    const [level, setLevel] = useState<Level>('root');

    const changeOpen = (next: boolean) => {
        if (!next) {
            setLevel('root');
        }

        onOpenChange(next);
    };
    const close = () => changeOpen(false);

    const levels: {
        id: Exclude<Level, 'root'>;
        label: string;
        meta?: string;
    }[] = [
        { id: 'settings', label: __('Settings') },
        {
            id: 'appearance',
            label: __('Appearance'),
            meta: menu.appearance.label,
        },
        { id: 'help', label: __('Help & community') },
    ];
    const current = levels.find((candidate) => candidate.id === level);

    return (
        <Drawer open={open} onOpenChange={changeOpen}>
            <DrawerContent
                className="bg-background data-[vaul-drawer-direction=bottom]:max-h-[92vh]"
                data-testid="user-menu-sheet"
            >
                <DrawerTitle className="sr-only">{__('User menu')}</DrawerTitle>
                <DrawerDescription className="sr-only">
                    {current?.label ?? __('User menu')}
                </DrawerDescription>

                {current ? (
                    <div className="flex flex-col gap-4 overflow-y-auto px-4 pt-2 pb-6">
                        <div className="flex items-center gap-1">
                            <button
                                type="button"
                                aria-label={__('Back')}
                                onClick={() => setLevel('root')}
                                className="flex size-11 items-center justify-center rounded-lg hover:bg-accent"
                            >
                                <ChevronLeft className="size-5" />
                            </button>
                            <h2 className="text-[17px] font-semibold">
                                {current.label}
                            </h2>
                        </div>

                        {level === 'settings' && (
                            <GroupCards
                                groups={menu.settingsGroups}
                                onNavigate={close}
                            />
                        )}
                        {level === 'help' && (
                            <GroupCards
                                groups={menu.helpGroups}
                                onNavigate={close}
                            />
                        )}
                        {level === 'appearance' && (
                            <>
                                <ChoiceCard choices={menu.appearance} />
                                <ChoiceCard choices={menu.chartScheme} />
                                <RowCard>
                                    <EntryRow
                                        entry={{
                                            kind: 'link',
                                            id: 'more-appearance',
                                            label: __(
                                                'More appearance options…',
                                            ),
                                            href: menu.moreAppearanceHref,
                                        }}
                                        onNavigate={close}
                                    />
                                </RowCard>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-4 overflow-y-auto px-4 pt-3 pb-6">
                        <div className="flex items-center gap-3 px-1 pt-1 text-left text-sm">
                            <UserInfo user={user} showEmail={true} />
                            {menu.planLabel && (
                                <PlanBadge
                                    label={menu.planLabel}
                                    isPro={menu.isPro}
                                />
                            )}
                        </div>

                        {menu.achievements && (
                            <Card size="flush" className="overflow-hidden">
                                <Link
                                    href={menu.progressHref}
                                    prefetch
                                    onClick={close}
                                    className="flex px-3.5 py-3 transition-colors hover:bg-accent"
                                >
                                    <UserMenuProgress
                                        achievements={menu.achievements}
                                        className="text-sm font-medium"
                                        barClassName="h-1.5"
                                    />
                                </Link>
                            </Card>
                        )}

                        <div>
                            <div className={GROUP_LABEL_CLASS}>
                                {__('Organize')}
                            </div>
                            <div className="grid grid-cols-4 gap-2">
                                {[
                                    menu.accounts,
                                    ...menu.organize.slice(0, 3),
                                ].map((entry) => (
                                    <Tile
                                        key={entry.id}
                                        entry={entry}
                                        onNavigate={close}
                                    />
                                ))}
                            </div>
                        </div>

                        <RowCard>
                            {menu.organize.slice(3).map((entry) => (
                                <EntryRow
                                    key={entry.id}
                                    entry={entry}
                                    onNavigate={close}
                                />
                            ))}
                            <button
                                type="button"
                                role="switch"
                                aria-checked={menu.privacy.enabled}
                                className={ROW_CLASS}
                                onClick={menu.privacy.toggle}
                            >
                                {__('Privacy mode')}
                                <UserMenuSwitch
                                    checked={menu.privacy.enabled}
                                    size="md"
                                />
                            </button>
                            {levels.map((candidate) => (
                                <button
                                    key={candidate.id}
                                    type="button"
                                    className={ROW_CLASS}
                                    onClick={() => setLevel(candidate.id)}
                                >
                                    {candidate.label}
                                    {candidate.meta && (
                                        <span className={META_CLASS}>
                                            {candidate.meta}
                                        </span>
                                    )}
                                    <ChevronRight
                                        aria-hidden="true"
                                        className={cn(
                                            'size-4 text-muted-foreground',
                                            !candidate.meta && 'ml-auto',
                                        )}
                                    />
                                </button>
                            ))}
                            {menu.adminHref && (
                                <EntryRow
                                    entry={{
                                        kind: 'link',
                                        id: 'admin',
                                        label: __('Admin'),
                                        href: menu.adminHref,
                                        prefetch: false,
                                    }}
                                    onNavigate={close}
                                />
                            )}
                        </RowCard>

                        <RowCard>
                            <Link
                                href={menu.logoutRoute}
                                as="button"
                                onClick={() => {
                                    menu.onLogout();
                                    close();
                                }}
                                className={cn(ROW_CLASS, 'text-destructive')}
                                data-test="logout-button"
                            >
                                {__('Log out')}
                            </Link>
                        </RowCard>

                        <span className="text-center text-xs text-muted-foreground">
                            {__('Version :version', { version: menu.version })}
                        </span>
                    </div>
                )}
            </DrawerContent>
        </Drawer>
    );
}

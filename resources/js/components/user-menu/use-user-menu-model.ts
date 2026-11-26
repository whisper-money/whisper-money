import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as adminIndex } from '@/actions/App/Http/Controllers/AdminController';
import { index as bankAccountsIndex } from '@/actions/App/Http/Controllers/Settings/AccountController';
import { index as apiTokensIndex } from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import { index as automationRulesIndex } from '@/actions/App/Http/Controllers/Settings/AutomationRuleController';
import { index as categoriesIndex } from '@/actions/App/Http/Controllers/Settings/CategoryController';
import { index as fullImportIndex } from '@/actions/App/Http/Controllers/Settings/FullImportController';
import { index as labelsIndex } from '@/actions/App/Http/Controllers/Settings/LabelController';
import { index as mcpIndex } from '@/actions/App/Http/Controllers/Settings/McpTokenController';
import { index as notificationsIndex } from '@/actions/App/Http/Controllers/Settings/NotificationPreferenceController';
import DiscordIcon from '@/components/icons/DiscordIcon';
import { usePrivacyMode } from '@/contexts/privacy-mode-context';
import { useAppearance, type Appearance } from '@/hooks/use-appearance';
import {
    CHART_COLOR_SCHEMES,
    useChartColorScheme,
} from '@/hooks/use-chart-color-scheme';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { settingsVisibility } from '@/lib/settings-visibility';
import { logout } from '@/routes';
import { edit as editAccount } from '@/routes/account';
import { index as progress } from '@/routes/achievements';
import { edit as editAppearance } from '@/routes/appearance';
import documentation from '@/routes/documentation';
import { index as monthlySummaries } from '@/routes/monthly-summaries';
import { billing } from '@/routes/settings';
import { index as connectionsIndex } from '@/routes/settings/connections';
import {
    type AchievementsProgress,
    type ChartColorScheme,
    type SharedData,
} from '@/types';
import { __ } from '@/utils/i18n';
import { router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    CreditCard,
    FileText,
    Landmark,
    Layers,
    LifeBuoy,
    MessageSquare,
    Tag,
    Zap,
    type LucideIcon,
} from 'lucide-react';
import { type ComponentType } from 'react';

type MenuIcon = LucideIcon | ComponentType<{ className?: string }>;

/** A destination: an app page, or an external page opened in a new tab. */
export interface UserMenuLink {
    kind: 'link';
    id: string;
    label: string;
    /** The short label a tile shows on the phone, when it differs. */
    shortLabel?: string;
    icon?: MenuIcon;
    href: string;
    /** Opened in a new tab, as a plain anchor. */
    external?: boolean;
    /** Off for heavy pages nobody should load on a hover. */
    prefetch?: boolean;
    meta?: string;
}

/** An entry that opens something in place (a dialog) instead of navigating. */
export interface UserMenuAction {
    kind: 'action';
    id: string;
    label: string;
    icon?: MenuIcon;
    onSelect: () => void;
    meta?: string;
}

export type UserMenuEntry = UserMenuLink | UserMenuAction;

export interface UserMenuGroup {
    id: string;
    title?: string;
    entries: UserMenuEntry[];
}

export interface UserMenuChoice<T extends string> {
    value: T;
    label: string;
}

/** A setting picked from a short list: a radio group under a title. */
export interface UserMenuChoiceSet<T extends string> {
    title: string;
    value: T;
    options: UserMenuChoice<T>[];
    select: (value: T) => void;
}

export interface UserMenuModel {
    planLabel: string | null;
    isPro: boolean;
    achievements: AchievementsProgress | null;
    progressHref: string;
    /** The mobile-only first tile: the Accounts page, not settings' bank accounts. */
    accounts: UserMenuLink;
    organize: UserMenuLink[];
    settingsGroups: UserMenuGroup[];
    helpGroups: UserMenuGroup[];
    /** The theme, with the current one's label for the submenu trigger. */
    appearance: UserMenuChoiceSet<Appearance> & { label: string };
    chartScheme: UserMenuChoiceSet<ChartColorScheme>;
    moreAppearanceHref: string;
    privacy: { enabled: boolean; toggle: () => void };
    adminHref: string | null;
    /** A POST: links to it must keep the route's method. */
    logoutRoute: ReturnType<typeof logout>;
    onLogout: () => void;
    version: string;
    /** Undoes the pointer lock a closing menu can leave on <body>. */
    cleanup: () => void;
}

const DISCORD_URL = 'https://discord.gg/m8hUhx6D9D';
const FEEDBACK_URL = 'https://whispermoney.userjot.com/';

function link(
    id: string,
    label: string,
    href: string,
    extra: Partial<UserMenuLink> = {},
): UserMenuLink {
    return { kind: 'link', id, label, href, ...extra };
}

/** Drops the groups a reader is offered nothing in. */
function nonEmpty(groups: UserMenuGroup[]): UserMenuGroup[] {
    return groups.filter((group) => group.entries.length > 0);
}

/**
 * Everything the reorganised user menu offers, already filtered for the
 * reader: the desktop dropdown and the phone's sheet both render from it, so
 * the two never disagree about an entry.
 */
export function useUserMenuModel({
    onOpenSupport,
    onOpenIntegrationRequests,
}: {
    onOpenSupport: () => void;
    onOpenIntegrationRequests: () => void;
}): UserMenuModel {
    const cleanup = useMobileNavigation();
    const { isPrivacyModeEnabled, togglePrivacyMode } = usePrivacyMode();
    const { appearance, updateAppearance } = useAppearance();
    const { scheme, saveScheme } = useChartColorScheme();
    const { auth, version, achievements, subscriptionsEnabled, features } =
        usePage<SharedData>().props;

    const visible = settingsVisibility({
        subscriptionsEnabled,
        isDemoAccount: auth.isDemoAccount ?? false,
        isSharedAccount: auth.isSharedAccount ?? false,
        // Outside the settings pages the server only says whether an import
        // can start, so either answer opens the entry.
        showFullImport:
            (features?.fullImport ?? false) ||
            (features?.fullImportSettings ?? false),
    });
    const isPro = auth.hasProPlan;
    const planLabel = subscriptionsEnabled
        ? isPro
            ? 'Pro'
            : __('Free')
        : null;

    const appearanceOptions: UserMenuChoice<Appearance>[] = [
        { value: 'light', label: __('Light') },
        { value: 'dark', label: __('Dark') },
        { value: 'system', label: __('System') },
    ];

    return {
        planLabel,
        isPro,
        achievements: achievements ?? null,
        progressHref: progress.url(),
        accounts: link('accounts', __('Accounts'), accountsIndex.url(), {
            icon: CreditCard,
        }),
        organize: [
            link('categories', __('Categories'), categoriesIndex.url(), {
                icon: Layers,
            }),
            link('labels', __('Labels'), labelsIndex.url(), { icon: Tag }),
            link(
                'automation-rules',
                __('Automation rules'),
                automationRulesIndex.url(),
                { icon: Zap, shortLabel: __('Rules') },
            ),
            link(
                'monthly-summaries',
                __('Monthly summaries'),
                monthlySummaries.url(),
                { icon: FileText },
            ),
        ],
        settingsGroups: nonEmpty([
            {
                id: 'data',
                title: __('Data'),
                entries: [
                    link(
                        'bank-accounts',
                        __('Bank accounts'),
                        bankAccountsIndex.url(),
                    ),
                    link(
                        'connections',
                        __('Connections'),
                        connectionsIndex.url(),
                    ),
                    ...(visible.fullImport
                        ? [
                              link(
                                  'full-import',
                                  __('Import from another app'),
                                  fullImportIndex.url(),
                              ),
                          ]
                        : []),
                ],
            },
            {
                id: 'account',
                title: __('Account'),
                entries: [
                    ...(visible.userAccount
                        ? [link('profile', __('Profile'), editAccount.url())]
                        : []),
                    ...(visible.notifications
                        ? [
                              link(
                                  'notifications',
                                  __('Notifications'),
                                  notificationsIndex.url(),
                              ),
                          ]
                        : []),
                    ...(visible.managePlan
                        ? [
                              link(
                                  'billing',
                                  __('Plan and billing'),
                                  billing.url(),
                                  { meta: planLabel ?? undefined },
                              ),
                          ]
                        : []),
                ],
            },
            {
                id: 'integrations',
                title: __('Integrations'),
                entries: [
                    ...(visible.aiConnector
                        ? [
                              link(
                                  'ai-connector',
                                  __('AI Connector (MCP)'),
                                  mcpIndex.url(),
                              ),
                          ]
                        : []),
                    ...(visible.api
                        ? [link('api', __('API'), apiTokensIndex.url())]
                        : []),
                ],
            },
        ]),
        helpGroups: [
            {
                id: 'help',
                entries: [
                    link(
                        'documentation',
                        __('Documentation'),
                        documentation.index.url(),
                        { icon: BookOpen, external: true },
                    ),
                    {
                        kind: 'action',
                        id: 'support',
                        label: __('Support'),
                        icon: LifeBuoy,
                        onSelect: onOpenSupport,
                    },
                    link('community', __('Community (Discord)'), DISCORD_URL, {
                        icon: DiscordIcon,
                        external: true,
                        meta: '↗',
                    }),
                ],
            },
            {
                id: 'ideas',
                title: __('Ideas'),
                entries: [
                    link('feedback', __('Send feedback'), FEEDBACK_URL, {
                        icon: MessageSquare,
                        external: true,
                        meta: '↗',
                    }),
                    {
                        kind: 'action',
                        id: 'request-integration',
                        label: __('Request integration'),
                        icon: Landmark,
                        onSelect: onOpenIntegrationRequests,
                    },
                ],
            },
        ],
        appearance: {
            title: __('Theme'),
            value: appearance,
            label:
                appearanceOptions.find((option) => option.value === appearance)
                    ?.label ?? '',
            options: appearanceOptions,
            select: updateAppearance,
        },
        chartScheme: {
            title: __('Charts'),
            value: scheme,
            options: CHART_COLOR_SCHEMES.map(({ value, label }) => ({
                value,
                label: __(label),
            })),
            select: saveScheme,
        },
        moreAppearanceHref: editAppearance.url(),
        privacy: {
            enabled: isPrivacyModeEnabled,
            toggle: togglePrivacyMode,
        },
        adminHref: auth.isAdmin ? adminIndex.url() : null,
        logoutRoute: logout(),
        onLogout: () => {
            cleanup();
            router.flushAll();
        },
        version,
        cleanup,
    };
}

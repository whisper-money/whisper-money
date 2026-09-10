import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';
import { CurrencyCode, CurrencyOption } from './account';
import { PricingConfig } from './pricing';
import { UUID } from './uuid';

export interface Auth {
    user: User;
    hasProPlan: boolean;
    /** The public demo account, which is not allowed to use the AI Connector. */
    isDemoAccount: boolean;
    /** The demo or the press account: public credentials, shared data. */
    isSharedAccount: boolean;
    /** The single ADMIN_EMAIL account, the only one that can open /admin. */
    isAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

export interface NavItem {
    type: 'nav-item';
    title: string;
    mobileTitle?: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | ReactNode | null;
    isActive?: boolean;
}

export interface NavSectionHeader {
    type: 'section-header';
    title: string;
}

export interface NavDivider {
    type: 'divider';
}

/** How far through the medals the reader is, for the account menu. */
export interface AchievementsProgress {
    unlocked: number;
    total: number;
}

export interface Features {
    cashflow: boolean;
    calculateBalancesOnImport: boolean;
}

export interface ExpiredBankingConnectionNotification {
    id: UUID;
    aspsp_name: string;
    provider: string;
    valid_until: string | null;
    reconnect_url: string;
}

export interface SubscriptionPaymentIssueNotification {
    status: 'past_due';
    action_url: string;
}

export type AchievementRarity = 'common' | 'uncommon' | 'rare' | 'epic';

/**
 * A medal's number, as data rather than as a sentence: an amount has to be
 * written by `AmountDisplay` so privacy mode can blank it.
 */
export interface AchievementFigureValue {
    type: 'money' | 'percent' | 'months' | 'weeks' | 'days' | 'count';
    value: number;
    currency: string | null;
}

/**
 * Where a medal stands: earned, the next rung of its track — named, so there is
 * something to aim at — or still a silhouette carrying only its tier.
 */
export type AchievementState = 'earned' | 'next' | 'locked';

/**
 * How far along the next medal of a track is. Only the tracks whose current
 * figure is cheap to read get one; the money ones arrive without.
 */
export interface AchievementProgress {
    now: number;
    goal: number;
    /**
     * Already past the goal with the medal not recorded yet. Visit medals are
     * settled on the request that earns them, so this is the nightly sweep's
     * tracks — and, for visits, only a reader it has never run for.
     */
    unlocking: boolean;
}

/** One medal on the progress screen. A locked one carries only its tier. */
export interface AchievementMedal {
    key: string;
    rarity: AchievementRarity;
    /** Share of evaluated members holding it, or null below the floor. */
    share: number | null;
    state: AchievementState;
    name: string | null;
    icon: string | null;
    /** The milestone it stands for. */
    figure: AchievementFigureValue | null;
    /** What was actually reached on the day. Never set on the next one. */
    reached: AchievementFigureValue | null;
    achieved_on: string | null;
    /** Only ever set on the next medal of a track, and not on all of those. */
    progress: AchievementProgress | null;
}

/**
 * The next rung of one track, as the header panel and the categorize prompt
 * draw it: enough to put a medal, a name and a bar on the screen, and nothing
 * the progress screen needs on top of that.
 */
export interface ChallengeMedal {
    /** The catalog key, e.g. `visits.3`. Stable across renames and rethresholds. */
    key: string;
    track: string;
    rarity: AchievementRarity;
    icon: string;
    name: string;
    figure: AchievementFigureValue | null;
    progress: AchievementProgress | null;
}

/**
 * The two medals the chrome puts in front of the reader on every screen.
 *
 * `visit_streak` is the run as it stands today — what the pill counts, because
 * a number with nothing to lose is not a streak. The bars under the medals are
 * measured on the longest run instead, the way the progress screen measures
 * them, so the two screens cannot disagree.
 */
export interface Challenges {
    visit_streak: number;
    /** `visits` then `visit_weeks`. A finished track is absent. */
    medals: ChallengeMedal[];
    /**
     * The visit medal that landed most recently, with no progress bar: it is on
     * the shelf. Null for a reader holding none. Visit medals are awarded on the
     * request that earns them, so this is how the shell knows to say so.
     */
    unlocked: ChallengeMedal | null;
    /** Null when there is nothing to categorize, or the prompt is snoozed. */
    uncategorized: {
        count: number;
        medal: ChallengeMedal | null;
    } | null;
}

export interface AchievementTrack {
    key: string;
    label: string;
    note: string | null;
    unlocked: number;
    medals: AchievementMedal[];
}

export type NotificationKind =
    | 'monthly_summary'
    | 'achievement'
    | 'achievements_welcome'
    | 'other';

/** One row in the bell, already worded for the reader's language. */
export interface NotificationItem {
    id: UUID;
    kind: NotificationKind;
    title: string;
    body: string | null;
    /** Where opening the row lands. Null when the row is only informational. */
    url: string | null;
    read_at: string | null;
    created_at: string;
    /** Set on an achievement row: the milestone, for the client to write. */
    figure: AchievementFigureValue | null;
    /** Set on an achievement row, so the bell can draw the right medal. */
    rarity: AchievementRarity | null;
    icon: string | null;
}

export interface NotificationsBell {
    unread: number;
    recent: NotificationItem[];
}

export interface Flash {
    success: string | null;
    error: string | null;
    saved_automation_rule_id?: string | null;
}

export type ChartColorScheme = 'neutral' | 'colorful' | 'blue' | 'pink';

export interface SharedData {
    name: string;
    appUrl: string;
    version: string;
    quote: { message: string; author: string };
    auth: Auth;
    flash: Flash;
    chartColorScheme: ChartColorScheme;
    includeLoansInNetWorthChart: boolean;
    includeRealEstateInNetWorthChart: boolean;
    subscriptionsEnabled: boolean;
    demoEnabled: boolean;
    aiCategorizationUpsellRate: number;
    subscriptionPaymentIssue: SubscriptionPaymentIssueNotification | null;
    pricing: PricingConfig;
    sidebarOpen: boolean;
    features: Features;
    /** Null for guests, during onboarding and while the bell is switched off. */
    notifications: NotificationsBell | null;
    /** Null for guests. */
    achievements: AchievementsProgress | null;
    /** Null for guests. */
    challenges: Challenges | null;
    expiredBankingConnections: ExpiredBankingConnectionNotification[];
    hasEncryptedAccounts: boolean;
    hasEncryptedTransactions: boolean;
    hasEncryptionSetup: boolean;
    locale: string;
    translations: Record<string, string>;
    currencies: {
        profile: CurrencyOption[];
        accounts: CurrencyOption[];
        /** Minor-unit decimals per currency code, e.g. EUR 2, COP 0, BTC 8. */
        decimals: Record<string, number>;
    };
    /**
     * The regions offered in settings, as bare tags: the picker writes each
     * one's country name and live example with `Intl`.
     */
    formatLocales: string[];
    [key: string]: unknown;
}

export interface User {
    id: UUID;
    name: string;
    email: string;
    currency_code: CurrencyCode;
    locale: string | null;
    /** The region amounts and dates are written in, e.g. `es-MX`. */
    format_locale: string | null;
    timezone: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

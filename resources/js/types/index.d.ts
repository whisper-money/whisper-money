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

export interface Features {
    cashflow: boolean;
    calculateBalancesOnImport: boolean;
    /** Gates creating a split. Merging one back is always available. */
    splitTransactions: boolean;
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

export type NotificationKind = 'monthly_summary' | 'other';

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
    [key: string]: unknown;
}

export interface User {
    id: UUID;
    name: string;
    email: string;
    currency_code: CurrencyCode;
    locale: string | null;
    timezone: string | null;
    avatar?: string;
    email_verified_at: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
}

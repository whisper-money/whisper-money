import { InertiaLinkProps } from '@inertiajs/react';
import { LucideIcon } from 'lucide-react';
import { ReactNode } from 'react';
import { CurrencyCode, CurrencyOption } from './account';
import { PricingConfig } from './pricing';
import { UUID } from './uuid';

export interface Auth {
    user: User;
    hasProPlan: boolean;
    isDemoAccount: boolean;
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

export interface Features {
    cashflow: boolean;
    transactionSplitting: boolean;
    recurringTransactions: boolean;
    /** Cashflow shows the Available card and the savings/investments split. */
    cashflowAvailableFree: boolean;
    /** May start a full import from another app (switch on, onboarding window). */
    fullImport: boolean;
    /** Settings shows the import page: eligible, or an import can still be undone. */
    fullImportSettings: boolean;
    /** Shows the custom dashboards UI (selector, grid, widget sizes). */
    customDashboards: boolean;
}

/** A bank connection that stopped syncing until the user reconnects it. */
export interface BankConnectionIssue {
    id: UUID;
    aspsp_name: string;
    provider: string;
    /** `error`: a failure the scheduled sync gave up retrying. */
    reason: 'expired' | 'error';
    /** The bank's consent flow; null for API-key providers, fixed on the connections page. */
    reconnect_url: string | null;
}

export interface SubscriptionPaymentIssueNotification {
    status: 'past_due';
    action_url: string;
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
    /** False when the install carries no EnableBanking credentials. */
    openBankingEnabled: boolean;
    /** API-key providers on our curated beta list (`banking.beta_banks`). */
    betaProviders: string[];
    aiCategorizationUpsellRate: number;
    /** Whether the import wizards may ask the AI which column holds each field. */
    aiImportMappingEnabled: boolean;
    subscriptionPaymentIssue: SubscriptionPaymentIssueNotification | null;
    pricing: PricingConfig;
    sidebarOpen: boolean;
    features: Features;
    hasEncryptedAccounts: boolean;
    hasEncryptedTransactions: boolean;
    hasEncryptionSetup: boolean;
    /** Whether the header offers its own add-transaction button. */
    showHeaderAddTransaction: boolean;
    bankConnectionIssues: BankConnectionIssue[];
    locale: string;
    translations: Record<string, string>;
    currencies: {
        profile: CurrencyOption[];
        accounts: CurrencyOption[];
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
    /** Spellings a bank prints for this person, beyond their profile name. */
    bank_aliases: string[] | null;
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

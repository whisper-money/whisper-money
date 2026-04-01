import { __ } from '@/utils/i18n';
import {
    BadgeQuestionMarkIcon,
    Building2,
    CreditCard,
    FolderKanban,
    Home,
    LineChart,
    LucideIcon,
    PiggyBank,
    TrendingUp,
    Wallet,
} from 'lucide-react';
import { UUID } from './uuid';

export const ACCOUNT_TYPES = [
    'checking',
    'credit_card',
    'investment',
    'loan',
    'real_estate',
    'retirement',
    'savings',
    'others',
] as const;

export type AccountType = (typeof ACCOUNT_TYPES)[number];

export type CurrencyCode = string;

export interface CurrencyOption {
    code: CurrencyCode;
    name: string;
}

export interface Bank {
    id: UUID;
    user_id: UUID | null;
    name: string;
    logo: string | null;
}

export interface Account {
    id: UUID;
    name: string;
    name_iv: string | null;
    encrypted: boolean;
    bank: Bank | null;
    type: AccountType;
    currency_code: CurrencyCode;
    banking_connection_id: UUID | null;
    external_account_id: string | null;
    linked_at: string | null;
    linked_loan_account_id?: UUID | null;
}

export interface AccountBalance {
    id: UUID;
    account_id: UUID;
    balance_date: string;
    balance: number;
    invested_amount: number | null;
    created_at: string;
    updated_at: string;
}

export const PROPERTY_TYPES = [
    'residential',
    'commercial',
    'land',
    'vacation',
    'other',
] as const;

export type PropertyType = (typeof PROPERTY_TYPES)[number];

export const AREA_UNITS = ['sqm', 'sqft', 'acres', 'hectares'] as const;

export type AreaUnit = (typeof AREA_UNITS)[number];

export interface RealEstateDetail {
    id: UUID;
    property_type: PropertyType;
    address: string | null;
    purchase_price: number | null;
    purchase_date: string | null;
    area_value: string | null;
    area_unit: AreaUnit | null;
    notes: string | null;
    revaluation_percentage: number | null;
    linked_loan_account_id: UUID | null;
    linked_loan_account: Account | null;
    current_market_value: number | null;
    current_loan_balance: number | null;
}

export interface LoanDetail {
    id: UUID;
    annual_interest_rate: string;
    loan_term_months: number;
    start_date: string;
    original_amount: number;
    monthly_payment: number | null;
    remaining_months: number | null;
}

export function formatPropertyType(type: PropertyType): string {
    const typeMap: Record<PropertyType, string> = {
        residential: __('Residential'),
        commercial: __('Commercial'),
        land: __('Land'),
        vacation: __('Vacation'),
        other: __('Other'),
    };
    return typeMap[type] || type;
}

export function formatAreaUnit(unit: AreaUnit): string {
    const unitMap: Record<AreaUnit, string> = {
        sqm: __('m²'),
        sqft: __('ft²'),
        acres: __('acres'),
        hectares: __('ha'),
    };
    return unitMap[unit] || unit;
}

export function formatAccountType(type: AccountType): string {
    const typeMap: Record<AccountType, string> = {
        checking: __('Checking'),
        credit_card: __('Credit Card'),
        investment: __('Investment'),
        loan: __('Loan'),
        real_estate: __('Real Estate'),
        retirement: __('Retirement / Pension'),
        savings: __('Savings'),
        others: __('Others'),
    };
    return typeMap[type] || type;
}

const NON_TRANSACTIONAL_ACCOUNT_TYPES: AccountType[] = [
    'investment',
    'loan',
    'real_estate',
    'retirement',
];

export function isTransactionalAccount(account: Account): boolean {
    return !NON_TRANSACTIONAL_ACCOUNT_TYPES.includes(account.type);
}

const INVESTED_AMOUNT_ACCOUNT_TYPES: AccountType[] = [
    'investment',
    'retirement',
    'savings',
];

export function supportsInvestedAmount(
    account: Pick<Account, 'type'>,
): boolean {
    return INVESTED_AMOUNT_ACCOUNT_TYPES.includes(account.type);
}

export function accountIconByType(type: AccountType): LucideIcon {
    const typeMap: Record<AccountType, LucideIcon> = {
        checking: Wallet,
        credit_card: CreditCard,
        investment: LineChart,
        loan: Building2,
        real_estate: Home,
        retirement: TrendingUp,
        savings: PiggyBank,
        others: FolderKanban,
    };

    return typeMap[type] ?? BadgeQuestionMarkIcon;
}

export function filterTransactionalAccounts<T extends { type: AccountType }>(
    accounts: T[],
): T[] {
    return accounts.filter(
        (account) => !NON_TRANSACTIONAL_ACCOUNT_TYPES.includes(account.type),
    );
}

export function isLoanAccount(account: Pick<Account, 'type'>): boolean {
    return account.type === 'loan';
}

export function isRealEstateAccount(account: Pick<Account, 'type'>): boolean {
    return account.type === 'real_estate';
}

/**
 * Returns the appropriate term for "balance" based on account type.
 * Loan accounts use "owed amount", real estate uses "market value".
 */
export function balanceTerm(
    type: AccountType,
    variant: 'singular' | 'plural' = 'singular',
): string {
    if (type === 'loan') {
        return variant === 'plural' ? __('owed amounts') : __('owed amount');
    }
    if (type === 'real_estate') {
        return variant === 'plural' ? __('market values') : __('market value');
    }
    return variant === 'plural' ? __('balances') : __('balance');
}

/**
 * Returns the appropriate capitalized term for "Balance" based on account type.
 * Loan accounts use "Owed Amount", real estate uses "Market Value".
 */
export function balanceTermCapitalized(
    type: AccountType,
    variant: 'singular' | 'plural' = 'singular',
): string {
    if (type === 'loan') {
        return variant === 'plural' ? __('Owed Amounts') : __('Owed Amount');
    }
    if (type === 'real_estate') {
        return variant === 'plural' ? __('Market Values') : __('Market Value');
    }
    return variant === 'plural' ? __('Balances') : __('Balance');
}

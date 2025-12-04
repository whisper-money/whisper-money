import { UUID } from './uuid';

export const ACCOUNT_TYPES = [
    'checking',
    'credit_card',
    'investment',
    'loan',
    'retirement',
    'savings',
    'others',
] as const;

export type AccountType = (typeof ACCOUNT_TYPES)[number];

export const CURRENCY_OPTIONS = [
    'USD',
    'EUR',
    'GBP',
    'JPY',
    'CHF',
    'CAD',
    'AUD',
    'CNY',
    'INR',
    'MXN',
] as const;

export type CurrencyCode = (typeof CURRENCY_OPTIONS)[number];

export interface Bank {
    id: UUID;
    user_id: UUID | null;
    name: string;
    logo: string | null;
}

export interface Account {
    id: UUID;
    name: string;
    name_iv: string;
    bank: Bank;
    type: AccountType;
    currency_code: CurrencyCode;
}

export interface AccountBalance {
    id: UUID;
    account_id: UUID;
    balance_date: string;
    balance: number;
    created_at: string;
    updated_at: string;
}

export function formatAccountType(type: AccountType): string {
    const typeMap: Record<AccountType, string> = {
        checking: 'Checking',
        credit_card: 'Credit Card',
        investment: 'Investment',
        loan: 'Loan',
        retirement: 'Retirement / Pension',
        savings: 'Savings',
        others: 'Others',
    };
    return typeMap[type] || type;
}

const NON_TRANSACTIONAL_ACCOUNT_TYPES: AccountType[] = [
    'investment',
    'retirement',
];

export function isTransactionalAccount(account: Account): boolean {
    return !NON_TRANSACTIONAL_ACCOUNT_TYPES.includes(account.type);
}

export function filterTransactionalAccounts<T extends { type: AccountType }>(
    accounts: T[],
): T[] {
    return accounts.filter(
        (account) => !NON_TRANSACTIONAL_ACCOUNT_TYPES.includes(account.type),
    );
}

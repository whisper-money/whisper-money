export const ACCOUNT_TYPES = [
    'checking',
    'credit_card',
    'loan',
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
    id: number;
    name: string;
    logo: string | null;
}

export interface Account {
    id: number;
    name: string;
    name_iv: string;
    bank: Bank;
    type: AccountType;
    currency_code: CurrencyCode;
}

export function formatAccountType(type: AccountType): string {
    const typeMap: Record<AccountType, string> = {
        checking: 'Checking',
        credit_card: 'Credit Card',
        loan: 'Loan',
        savings: 'Savings',
        others: 'Others',
    };
    return typeMap[type] || type;
}


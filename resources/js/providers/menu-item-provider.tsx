import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as budgetsIndex } from '@/actions/App/Http/Controllers/BudgetController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { cashflow, dashboard } from '@/routes';
import { Features, NavItem } from '@/types';
import {
    CreditCard,
    LayoutGrid,
    PiggyBank,
    Receipt,
    TrendingUp,
} from 'lucide-react';

const mobileLabels: Record<string, Record<string, string>> = {
    en: {
        dashboard: 'Home',
        cashflow: 'Cashflow',
        accounts: 'Accounts',
        transactions: 'Movements',
        budgets: 'Budget',
    },
    es: {
        dashboard: 'Inicio',
        cashflow: 'Cashflow',
        accounts: 'Cuentas',
        transactions: 'Movim.',
        budgets: 'Presup.',
    },
};

function getMobileLabel(key: string, locale: string): string {
    return (mobileLabels[locale] ?? mobileLabels['en'])[key];
}

export function getMainNavItems(features: Features, locale: string): NavItem[] {
    const items: NavItem[] = [
        {
            type: 'nav-item',
            title: 'Dashboard',
            mobileTitle: getMobileLabel('dashboard', locale),
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (features.cashflow) {
        items.push({
            type: 'nav-item',
            title: 'Cashflow',
            mobileTitle: getMobileLabel('cashflow', locale),
            href: cashflow(),
            icon: TrendingUp,
        });
    }

    items.push(
        {
            type: 'nav-item',
            title: 'Accounts',
            mobileTitle: getMobileLabel('accounts', locale),
            href: accountsIndex(),
            icon: CreditCard,
        },
        {
            type: 'nav-item',
            title: 'Transactions',
            mobileTitle: getMobileLabel('transactions', locale),
            href: transactionsIndex(),
            icon: Receipt,
        },
        {
            type: 'nav-item',
            title: 'Budgets',
            mobileTitle: getMobileLabel('budgets', locale),
            href: budgetsIndex(),
            icon: PiggyBank,
        },
    );

    return items;
}

export const footerNavItems: NavItem[] = [];

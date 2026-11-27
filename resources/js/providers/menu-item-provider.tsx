import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as budgetsIndex } from '@/actions/App/Http/Controllers/BudgetController';
import { index as forecastIndex } from '@/actions/App/Http/Controllers/ForecastController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import { cashflow, dashboard } from '@/routes';
import { Features, NavItem } from '@/types';
import {
    ChartSpline,
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
        forecast: 'Forecast',
        accounts: 'Accounts',
        transactions: 'Movements',
        budgets: 'Plan',
    },
    es: {
        dashboard: 'Inicio',
        cashflow: 'Cashflow',
        forecast: 'Previsión',
        accounts: 'Cuentas',
        transactions: 'Movim.',
        budgets: 'Plan',
    },
    fr: {
        dashboard: 'Accueil',
        cashflow: 'Trésorerie',
        forecast: 'Prévision',
        accounts: 'Comptes',
        transactions: 'Opérations',
        budgets: 'Plan',
    },
};

/**
 * Looked up by the language subtag, so a regional tag (`es-MX`, `fr-FR`) still
 * finds its labels; an unknown language falls back to English.
 */
function getMobileLabel(key: string, locale: string): string {
    const language = locale.split(/[-_]/)[0].toLowerCase();

    return (mobileLabels[language] ?? mobileLabels['en'])[key];
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

    if (features.forecast) {
        items.push({
            type: 'nav-item',
            title: 'Forecast',
            mobileTitle: getMobileLabel('forecast', locale),
            href: forecastIndex(),
            icon: ChartSpline,
        });
    }

    items.push(
        {
            type: 'nav-item',
            title: 'Accounts',
            mobileTitle: getMobileLabel('accounts', locale),
            href: accountsIndex(),
            icon: CreditCard,
            // Forecast takes its place in the mobile bar: the accounts stay
            // one tap away in the user menu's Organize grid and on the
            // dashboard's account cards.
            mobile: !features.forecast,
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
            title: 'Planning',
            mobileTitle: getMobileLabel('budgets', locale),
            href: budgetsIndex(),
            icon: PiggyBank,
        },
    );

    return items;
}

/** The mobile bottom bar: the main items, less those kept off it. */
export function getMobileNavItems(items: NavItem[]): NavItem[] {
    return items.filter((item) => item.mobile !== false);
}

export const footerNavItems: NavItem[] = [];

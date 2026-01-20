import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as budgetsIndex } from '@/actions/App/Http/Controllers/BudgetController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import DiscordIcon from '@/components/icons/DiscordIcon';
import { cashflow, dashboard } from '@/routes';
import { Features, NavItem } from '@/types';
import {
    CreditCard,
    Github,
    LayoutGrid,
    PiggyBank,
    Receipt,
    TrendingUp,
} from 'lucide-react';

export function getMainNavItems(features: Features): NavItem[] {
    const items: NavItem[] = [
        {
            type: 'nav-item',
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
    ];

    if (features.cashflow) {
        items.push({
            type: 'nav-item',
            title: 'Cashflow',
            href: cashflow(),
            icon: TrendingUp,
        });
    }

    items.push(
        {
            type: 'nav-item',
            title: 'Accounts',
            href: accountsIndex(),
            icon: CreditCard,
        },
        {
            type: 'nav-item',
            title: 'Transactions',
            href: transactionsIndex(),
            icon: Receipt,
        },
    );

    if (features.budgets) {
        items.push({
            type: 'nav-item',
            title: 'Budgets',
            href: budgetsIndex(),
            icon: PiggyBank,
        });
    }

    return items;
}

export const footerNavItems: NavItem[] = [
    {
        type: 'nav-item',
        title: 'Github',
        href: 'https://github.com/whisper-money/whisper-money',
        icon: Github,
    },
    {
        type: 'nav-item',
        title: 'Community',
        href: 'https://discord.gg/zqfrynthvb',
        icon: <DiscordIcon className="size-5" />,
    },
];

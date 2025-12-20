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

<<<<<<< HEAD
export function getMainNavItems(features: Features): NavItem[] {
=======
export const getMainNavItems = (features: { budgets: boolean }): NavItem[] => {
>>>>>>> 80e9936 (Add a feature flag for budgets using pennant)
    const items: NavItem[] = [
        {
            type: 'nav-item',
            title: 'Dashboard',
            href: dashboard(),
            icon: LayoutGrid,
        },
<<<<<<< HEAD
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
=======
>>>>>>> 80e9936 (Add a feature flag for budgets using pennant)
        {
            type: 'nav-item',
            title: 'Accounts',
            href: accountsIndex(),
            icon: CreditCard,
        },
<<<<<<< HEAD
        {
            type: 'nav-item',
            title: 'Transactions',
            href: transactionsIndex(),
            icon: Receipt,
        },
        {
=======
    ];

    if (features.budgets) {
        items.push({
>>>>>>> 80e9936 (Add a feature flag for budgets using pennant)
            type: 'nav-item',
            title: 'Budgets',
            href: budgetsIndex(),
            icon: PiggyBank,
<<<<<<< HEAD
        },
    );

    return items;
}
=======
        });
    }

    items.push({
        type: 'nav-item',
        title: 'Transactions',
        href: transactionsIndex(),
        icon: Receipt,
    });

    return items;
};
>>>>>>> 80e9936 (Add a feature flag for budgets using pennant)

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

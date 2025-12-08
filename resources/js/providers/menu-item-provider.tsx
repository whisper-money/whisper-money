import { index as accountsIndex } from '@/actions/App/Http/Controllers/AccountController';
import { index as transactionsIndex } from '@/actions/App/Http/Controllers/TransactionController';
import DiscordIcon from '@/components/icons/DiscordIcon';
import { dashboard } from '@/routes';
import { NavItem } from '@/types';
import { CreditCard, Github, LayoutGrid, Receipt } from 'lucide-react';

export const mainNavItems: NavItem[] = [
    {
        type: 'nav-item',
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
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
];

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

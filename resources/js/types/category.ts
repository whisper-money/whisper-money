import { __ } from '@/utils/i18n';

import { UUID } from './uuid';

export const CATEGORY_ICONS = [
    'AlertCircle',
    'AlertTriangle',
    'ArrowDownCircle',
    'ArrowLeftRight',
    'ArrowRightLeft',
    'ArrowUpCircle',
    'Baby',
    'BadgeAlert',
    'Banknote',
    'Bolt',
    'BookOpen',
    'Briefcase',
    'Building',
    'Building2',
    'Bus',
    'Car',
    'Cigarette',
    'Clapperboard',
    'Clock',
    'Coins',
    'CreditCard',
    'Dice5',
    'Dices',
    'DollarSign',
    'Droplets',
    'Dumbbell',
    'FileText',
    'Flame',
    'Fuel',
    'Gavel',
    'Gift',
    'Globe',
    'GraduationCap',
    'HandCoins',
    'HandHeart',
    'Heart',
    'HeartPulse',
    'HelpCircle',
    'Home',
    'Hotel',
    'Landmark',
    'LineChart',
    'Mail',
    'ParkingMeter',
    'PiggyBank',
    'Pizza',
    'Plane',
    'Puzzle',
    'Receipt',
    'ReceiptText',
    'Repeat',
    'RotateCcw',
    'RotateCw',
    'Scale',
    'Server',
    'Shield',
    'ShieldCheck',
    'Shirt',
    'ShoppingBag',
    'ShoppingBasket',
    'Sparkles',
    'Split',
    'Ticket',
    'TicketPercent',
    'TrendingUp',
    'Undo2',
    'Users',
    'Users2',
    'Utensils',
    'Wallet',
    'Wifi',
    'Wine',
    'Wrench',
] as const;

export type CategoryIcon = (typeof CATEGORY_ICONS)[number];

export const CATEGORY_COLORS = [
    'amber',
    'blue',
    'cyan',
    'emerald',
    'gray',
    'green',
    'indigo',
    'orange',
    'pink',
    'purple',
    'red',
    'rose',
    'violet',
    'stone',
    'neutral',
    'lime',
    'fuchsia',
    'slate',
    'teal',
    'yellow',
] as const;

export type CategoryColor = (typeof CATEGORY_COLORS)[number];

export const CATEGORY_TYPES = [
    'income',
    'expense',
    'transfer',
    'savings',
    'investment',
] as const;

export type CategoryType = (typeof CATEGORY_TYPES)[number];

const CATEGORY_TYPE_LABELS: Record<CategoryType, string> = {
    income: 'Income',
    expense: 'Expense',
    transfer: 'Transfer',
    savings: 'Savings',
    investment: 'Investment',
};

export function getCategoryTypeLabel(type: CategoryType): string {
    return __(CATEGORY_TYPE_LABELS[type]);
}

export const CATEGORY_CASHFLOW_DIRECTIONS = [
    'hidden',
    'inflow',
    'outflow',
] as const;

export type CategoryCashflowDirection =
    (typeof CATEGORY_CASHFLOW_DIRECTIONS)[number];

export const CATEGORY_MAX_DEPTH = 3;

export interface Category {
    id: UUID;
    name: string;
    icon: CategoryIcon;
    color: CategoryColor;
    type: CategoryType;
    cashflow_direction: CategoryCashflowDirection;
    parent_id: UUID | null;
}

export function getCategoryColorClasses(color: CategoryColor): {
    bg: string;
    text: string;
} {
    const colorMap: Record<CategoryColor, { bg: string; text: string }> = {
        amber: {
            bg: 'bg-amber-100 dark:bg-amber-700',
            text: 'text-amber-700 dark:text-amber-100',
        },
        rose: {
            bg: 'bg-rose-100 dark:bg-rose-700',
            text: 'text-rose-700 dark:text-rose-100',
        },
        blue: {
            bg: 'bg-blue-100 dark:bg-blue-700',
            text: 'text-blue-700 dark:text-blue-100',
        },
        violet: {
            bg: 'bg-violet-100 dark:bg-violet-700',
            text: 'text-violet-700 dark:text-violet-100',
        },
        cyan: {
            bg: 'bg-cyan-100 dark:bg-cyan-700',
            text: 'text-cyan-700 dark:text-cyan-100',
        },
        emerald: {
            bg: 'bg-emerald-100 dark:bg-emerald-700',
            text: 'text-emerald-700 dark:text-emerald-100',
        },
        gray: {
            bg: 'bg-gray-100 dark:bg-gray-700',
            text: 'text-gray-700 dark:text-gray-100',
        },
        green: {
            bg: 'bg-green-100 dark:bg-green-700',
            text: 'text-green-700 dark:text-green-100',
        },
        lime: {
            bg: 'bg-lime-100 dark:bg-lime-700',
            text: 'text-lime-700 dark:text-lime-100',
        },
        fuchsia: {
            bg: 'bg-fuchsia-100 dark:bg-fuchsia-700',
            text: 'text-fuchsia-700 dark:text-fuchsia-100',
        },
        indigo: {
            bg: 'bg-indigo-100 dark:bg-indigo-700',
            text: 'text-indigo-700 dark:text-indigo-100',
        },
        orange: {
            bg: 'bg-orange-100 dark:bg-orange-700',
            text: 'text-orange-700 dark:text-orange-100',
        },
        pink: {
            bg: 'bg-pink-100 dark:bg-pink-700',
            text: 'text-pink-700 dark:text-pink-100',
        },
        purple: {
            bg: 'bg-purple-100 dark:bg-purple-700',
            text: 'text-purple-700 dark:text-purple-100',
        },
        red: {
            bg: 'bg-red-100 dark:bg-red-700',
            text: 'text-red-700 dark:text-red-100',
        },
        slate: {
            bg: 'bg-slate-100 dark:bg-slate-700',
            text: 'text-slate-700 dark:text-slate-100',
        },
        stone: {
            bg: 'bg-stone-100 dark:bg-stone-700',
            text: 'text-stone-700 dark:text-stone-100',
        },
        neutral: {
            bg: 'bg-neutral-100 dark:bg-neutral-700',
            text: 'text-neutral-700 dark:text-neutral-100',
        },
        teal: {
            bg: 'bg-teal-100 dark:bg-teal-700',
            text: 'text-teal-700 dark:text-teal-100',
        },
        yellow: {
            bg: 'bg-yellow-100 dark:bg-yellow-700',
            text: 'text-yellow-700 dark:text-yellow-100',
        },
    };

    return colorMap[color] ?? colorMap.gray;
}

export function getCategoryChartColor(color: CategoryColor): string {
    const colorMap: Record<CategoryColor, string> = {
        amber: 'var(--color-amber-500)',
        blue: 'var(--color-blue-500)',
        cyan: 'var(--color-cyan-500)',
        emerald: 'var(--color-emerald-500)',
        fuchsia: 'var(--color-fuchsia-500)',
        gray: 'var(--color-gray-500)',
        green: 'var(--color-green-500)',
        indigo: 'var(--color-indigo-500)',
        lime: 'var(--color-lime-500)',
        neutral: 'var(--color-neutral-500)',
        orange: 'var(--color-orange-500)',
        pink: 'var(--color-pink-500)',
        purple: 'var(--color-purple-500)',
        red: 'var(--color-red-500)',
        rose: 'var(--color-rose-500)',
        slate: 'var(--color-slate-500)',
        stone: 'var(--color-stone-500)',
        teal: 'var(--color-teal-500)',
        violet: 'var(--color-violet-500)',
        yellow: 'var(--color-yellow-500)',
    };

    return colorMap[color] ?? 'var(--color-gray-500)';
}

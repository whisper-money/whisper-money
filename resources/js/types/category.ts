export const CATEGORY_ICONS = [
    'AlertCircle',
    'AlertTriangle',
    'ArrowDownCircle',
    'ArrowLeftRight',
    'ArrowUpCircle',
    'Baby',
    'Banknote',
    'Briefcase',
    'Building',
    'Building2',
    'Car',
    'Clock',
    'CreditCard',
    'Dices',
    'Dumbbell',
    'FileText',
    'Gift',
    'GraduationCap',
    'HandHeart',
    'Heart',
    'HelpCircle',
    'Home',
    'Landmark',
    'Mail',
    'PiggyBank',
    'Plane',
    'Receipt',
    'ReceiptText',
    'Repeat',
    'RotateCcw',
    'Scale',
    'Shield',
    'ShieldCheck',
    'ShoppingBag',
    'ShoppingBasket',
    'Globe',
    'TrendingUp',
    'Undo2',
    'Users',
    'Users2',
    'Utensils',
    'Wallet',
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
    'slate',
    'teal',
    'yellow',
] as const;

export type CategoryColor = (typeof CATEGORY_COLORS)[number];

export interface Category {
    id: number;
    name: string;
    icon: CategoryIcon;
    color: CategoryColor;
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
        blue: {
            bg: 'bg-blue-100 dark:bg-blue-700',
            text: 'text-blue-700 dark:text-blue-100',
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
        teal: {
            bg: 'bg-teal-100 dark:bg-teal-700',
            text: 'text-teal-700 dark:text-teal-100',
        },
        yellow: {
            bg: 'bg-yellow-100 dark:bg-yellow-700',
            text: 'text-yellow-700 dark:text-yellow-100',
        },
    };

    return colorMap[color];
}

export const CATEGORY_ICONS = [
    'ShoppingCart',
    'Home',
    'Car',
    'Utensils',
    'Film',
    'Heart',
    'Zap',
    'ShoppingBag',
    'Plane',
    'Coffee',
    'Smartphone',
    'Book',
    'DollarSign',
    'Gift',
    'Briefcase',
    'GamepadIcon',
    'Music',
    'Shirt',
] as const;

export type CategoryIcon = (typeof CATEGORY_ICONS)[number];

export const CATEGORY_COLORS = [
    'red',
    'orange',
    'amber',
    'yellow',
    'lime',
    'green',
    'emerald',
    'teal',
    'cyan',
    'sky',
    'blue',
    'indigo',
    'violet',
    'purple',
    'fuchsia',
    'pink',
    'rose',
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
        red: { bg: 'bg-red-100 dark:bg-red-700', text: 'text-red-700 dark:text-red-100' },
        orange: { bg: 'bg-orange-100 dark:bg-orange-700', text: 'text-orange-700 dark:text-orange-100' },
        amber: { bg: 'bg-amber-100 dark:bg-amber-700', text: 'text-amber-700 dark:text-amber-100' },
        yellow: { bg: 'bg-yellow-100 dark:bg-yellow-700', text: 'text-yellow-700 dark:text-yellow-100' },
        lime: { bg: 'bg-lime-100 dark:bg-lime-700', text: 'text-lime-700 dark:text-lime-100' },
        green: { bg: 'bg-green-100 dark:bg-green-700', text: 'text-green-700 dark:text-green-100' },
        emerald: { bg: 'bg-emerald-100 dark:bg-emerald-700', text: 'text-emerald-700 dark:text-emerald-100' },
        teal: { bg: 'bg-teal-100 dark:bg-teal-700', text: 'text-teal-700 dark:text-teal-100' },
        cyan: { bg: 'bg-cyan-100 dark:bg-cyan-700', text: 'text-cyan-700 dark:text-cyan-100' },
        sky: { bg: 'bg-sky-100 dark:bg-sky-700', text: 'text-sky-700 dark:text-sky-100' },
        blue: { bg: 'bg-blue-100 dark:bg-blue-700', text: 'text-blue-700 dark:text-blue-100' },
        indigo: { bg: 'bg-indigo-100 dark:bg-indigo-700', text: 'text-indigo-700 dark:text-indigo-100' },
        violet: { bg: 'bg-violet-100 dark:bg-violet-700', text: 'text-violet-700 dark:text-violet-100' },
        purple: { bg: 'bg-purple-100 dark:bg-purple-700', text: 'text-purple-700 dark:text-purple-100' },
        fuchsia: { bg: 'bg-fuchsia-100 dark:bg-fuchsia-700', text: 'text-fuchsia-700 dark:text-fuchsia-100' },
        pink: { bg: 'bg-pink-100 dark:bg-pink-700', text: 'text-pink-700 dark:text-pink-100' },
        rose: { bg: 'bg-rose-100 dark:bg-rose-700', text: 'text-rose-700 dark:text-rose-100' },
    };

    return colorMap[color];
}


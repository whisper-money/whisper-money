import { UUID } from './uuid';

export interface Label {
    id: UUID;
    user_id: UUID;
    name: string;
    color: string;
    created_at: string;
    updated_at: string;
    deleted_at: string | null;
}

export const LABEL_COLORS = [
    'amber',
    'blue',
    'cyan',
    'emerald',
    'fuchsia',
    'gray',
    'green',
    'indigo',
    'lime',
    'neutral',
    'orange',
    'pink',
    'purple',
    'red',
    'rose',
    'slate',
    'stone',
    'teal',
    'violet',
    'yellow',
] as const;

export type LabelColor = (typeof LABEL_COLORS)[number];

export function getLabelColorClasses(color: string): {
    bg: string;
    text: string;
    border: string;
} {
    const colorMap: Record<
        string,
        { bg: string; text: string; border: string }
    > = {
        amber: {
            bg: 'bg-amber-100 dark:bg-amber-900/30',
            text: 'text-amber-700 dark:text-amber-300',
            border: 'border-amber-300 dark:border-amber-700',
        },
        blue: {
            bg: 'bg-blue-100 dark:bg-blue-900/30',
            text: 'text-blue-700 dark:text-blue-300',
            border: 'border-blue-300 dark:border-blue-700',
        },
        cyan: {
            bg: 'bg-cyan-100 dark:bg-cyan-900/30',
            text: 'text-cyan-700 dark:text-cyan-300',
            border: 'border-cyan-300 dark:border-cyan-700',
        },
        emerald: {
            bg: 'bg-emerald-100 dark:bg-emerald-900/30',
            text: 'text-emerald-700 dark:text-emerald-300',
            border: 'border-emerald-300 dark:border-emerald-700',
        },
        fuchsia: {
            bg: 'bg-fuchsia-100 dark:bg-fuchsia-900/30',
            text: 'text-fuchsia-700 dark:text-fuchsia-300',
            border: 'border-fuchsia-300 dark:border-fuchsia-700',
        },
        gray: {
            bg: 'bg-gray-100 dark:bg-gray-900/30',
            text: 'text-gray-700 dark:text-gray-300',
            border: 'border-gray-300 dark:border-gray-700',
        },
        green: {
            bg: 'bg-green-100 dark:bg-green-900/30',
            text: 'text-green-700 dark:text-green-300',
            border: 'border-green-300 dark:border-green-700',
        },
        indigo: {
            bg: 'bg-indigo-100 dark:bg-indigo-900/30',
            text: 'text-indigo-700 dark:text-indigo-300',
            border: 'border-indigo-300 dark:border-indigo-700',
        },
        lime: {
            bg: 'bg-lime-100 dark:bg-lime-900/30',
            text: 'text-lime-700 dark:text-lime-300',
            border: 'border-lime-300 dark:border-lime-700',
        },
        neutral: {
            bg: 'bg-neutral-100 dark:bg-neutral-900/30',
            text: 'text-neutral-700 dark:text-neutral-300',
            border: 'border-neutral-300 dark:border-neutral-700',
        },
        orange: {
            bg: 'bg-orange-100 dark:bg-orange-900/30',
            text: 'text-orange-700 dark:text-orange-300',
            border: 'border-orange-300 dark:border-orange-700',
        },
        pink: {
            bg: 'bg-pink-100 dark:bg-pink-900/30',
            text: 'text-pink-700 dark:text-pink-300',
            border: 'border-pink-300 dark:border-pink-700',
        },
        purple: {
            bg: 'bg-purple-100 dark:bg-purple-900/30',
            text: 'text-purple-700 dark:text-purple-300',
            border: 'border-purple-300 dark:border-purple-700',
        },
        red: {
            bg: 'bg-red-100 dark:bg-red-900/30',
            text: 'text-red-700 dark:text-red-300',
            border: 'border-red-300 dark:border-red-700',
        },
        rose: {
            bg: 'bg-rose-100 dark:bg-rose-900/30',
            text: 'text-rose-700 dark:text-rose-300',
            border: 'border-rose-300 dark:border-rose-700',
        },
        slate: {
            bg: 'bg-slate-100 dark:bg-slate-900/30',
            text: 'text-slate-700 dark:text-slate-300',
            border: 'border-slate-300 dark:border-slate-700',
        },
        stone: {
            bg: 'bg-stone-100 dark:bg-stone-900/30',
            text: 'text-stone-700 dark:text-stone-300',
            border: 'border-stone-300 dark:border-stone-700',
        },
        teal: {
            bg: 'bg-teal-100 dark:bg-teal-900/30',
            text: 'text-teal-700 dark:text-teal-300',
            border: 'border-teal-300 dark:border-teal-700',
        },
        violet: {
            bg: 'bg-violet-100 dark:bg-violet-900/30',
            text: 'text-violet-700 dark:text-violet-300',
            border: 'border-violet-300 dark:border-violet-700',
        },
        yellow: {
            bg: 'bg-yellow-100 dark:bg-yellow-900/30',
            text: 'text-yellow-700 dark:text-yellow-300',
            border: 'border-yellow-300 dark:border-yellow-700',
        },
    };

    return (
        colorMap[color] || {
            bg: 'bg-gray-100 dark:bg-gray-900/30',
            text: 'text-gray-700 dark:text-gray-300',
            border: 'border-gray-300 dark:border-gray-700',
        }
    );
}

import { cn } from '@/lib/utils';
import { type User } from '@/types';
import { Facehash } from 'facehash';

export const tailwindColors500 = [
    'var(--color-slate-500)',
    'var(--color-gray-500)',
    'var(--color-zinc-500)',
    'var(--color-neutral-500)',
    'var(--color-stone-500)',
    'var(--color-red-500)',
    'var(--color-orange-500)',
    'var(--color-amber-500)',
    'var(--color-yellow-500)',
    'var(--color-lime-500)',
    'var(--color-green-500)',
    'var(--color-emerald-500)',
    'var(--color-teal-500)',
    'var(--color-cyan-500)',
    'var(--color-sky-500)',
    'var(--color-blue-500)',
    'var(--color-indigo-500)',
    'var(--color-violet-500)',
    'var(--color-purple-500)',
    'var(--color-fuchsia-500)',
    'var(--color-pink-500)',
    'var(--color-rose-500)',
];

export const tailwindColors200 = [
    'var(--color-slate-200)',
    'var(--color-gray-200)',
    'var(--color-zinc-200)',
    'var(--color-neutral-200)',
    'var(--color-stone-200)',
    'var(--color-red-200)',
    'var(--color-orange-200)',
    'var(--color-amber-200)',
    'var(--color-yellow-200)',
    'var(--color-lime-200)',
    'var(--color-green-200)',
    'var(--color-emerald-200)',
    'var(--color-teal-200)',
    'var(--color-cyan-200)',
    'var(--color-sky-200)',
    'var(--color-blue-200)',
    'var(--color-indigo-200)',
    'var(--color-violet-200)',
    'var(--color-purple-200)',
    'var(--color-fuchsia-200)',
    'var(--color-pink-200)',
    'var(--color-rose-200)',
];

export function UserInfo({
    user,
    showEmail = false,
    hideNameOnMobile = false,
}: {
    user: User;
    showEmail?: boolean;
    hideNameOnMobile?: boolean;
}) {
    return (
        <>
            <Facehash
                name={user.name}
                size={32}
                colors={[...tailwindColors200, ...tailwindColors500]}
                intensity3d="dramatic"
                className="rounded-full"
            />
            <div
                className={cn([
                    'grid flex-1 text-left text-sm leading-tight',
                    { 'hidden sm:grid': hideNameOnMobile },
                ])}
            >
                <span className="truncate font-medium">{user.name}</span>
                {showEmail && (
                    <span className="truncate text-xs text-muted-foreground">
                        {user.email}
                    </span>
                )}
            </div>
        </>
    );
}

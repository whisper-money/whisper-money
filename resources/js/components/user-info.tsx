import { cn } from '@/lib/utils';
import { type User } from '@/types';
import { Facehash } from 'facehash';
import { Avatar } from './ui/avatar';

export const tailwindColorClasses = [
    'bg-slate-200 dark:bg-slate-800',
    'bg-gray-200 dark:bg-gray-800',
    'bg-zinc-200 dark:bg-zinc-800',
    'bg-neutral-200 dark:bg-neutral-800',
    'bg-stone-200 dark:bg-stone-800',
    'bg-red-200 dark:bg-red-800',
    'bg-orange-200 dark:bg-orange-800',
    'bg-amber-200 dark:bg-amber-800',
    'bg-yellow-200 dark:bg-yellow-800',
    'bg-lime-200 dark:bg-lime-800',
    'bg-green-200 dark:bg-green-800',
    'bg-emerald-200 dark:bg-emerald-800',
    'bg-teal-200 dark:bg-teal-800',
    'bg-cyan-200 dark:bg-cyan-800',
    'bg-sky-200 dark:bg-sky-800',
    'bg-blue-200 dark:bg-blue-800',
    'bg-indigo-200 dark:bg-indigo-800',
    'bg-violet-200 dark:bg-violet-800',
    'bg-purple-200 dark:bg-purple-800',
    'bg-fuchsia-200 dark:bg-fuchsia-800',
    'bg-pink-200 dark:bg-pink-800',
    'bg-rose-200 dark:bg-rose-800',
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
            <Avatar>
                <Facehash
                    name={user.name}
                    size={32}
                    colorClasses={tailwindColorClasses}
                    intensity3d="dramatic"
                    className="rounded-full"
                    enableBlink
                />
            </Avatar>

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

import { cn } from '@/lib/utils';
import { Building2 } from 'lucide-react';
import { isValidElement, type ReactElement } from 'react';

interface BankLogoProps {
    src?: string | null;
    name?: string;
    className?: string;
    /** What to draw without a logo; an element is drawn as it is. */
    fallback?: 'letter' | 'icon' | 'empty' | 'none' | ReactElement;
}

export function BankLogo({
    src,
    name,
    className,
    fallback = 'none',
}: BankLogoProps) {
    if (src) {
        return (
            <img
                src={src}
                alt={name || ''}
                className={cn('rounded-full object-contain', className)}
            />
        );
    }

    if (isValidElement(fallback)) {
        return fallback;
    }

    if (fallback === 'none') {
        return null;
    }

    if (fallback === 'letter') {
        return (
            <div
                className={cn(
                    'flex items-center justify-center rounded bg-muted',
                    className,
                )}
            >
                <span className="font-medium text-muted-foreground">
                    {name?.charAt(0) || '?'}
                </span>
            </div>
        );
    }

    if (fallback === 'icon') {
        return (
            <div
                className={cn(
                    'flex items-center justify-center rounded bg-muted',
                    className,
                )}
            >
                <Building2 className="size-1/2 text-muted-foreground" />
            </div>
        );
    }

    return <div className={cn('rounded bg-muted', className)} />;
}

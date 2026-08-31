import { cn } from '@/lib/utils';
import { Building2 } from 'lucide-react';
import { useState } from 'react';

interface BankLogoProps {
    src?: string | null;
    name?: string;
    className?: string;
    fallback?: 'letter' | 'icon' | 'empty' | 'none';
}

export function BankLogo({
    src,
    name,
    className,
    fallback = 'none',
}: BankLogoProps) {
    // A bank's logo is a third-party URL that can 404 or go offline (open
    // banking CDNs rotate them), and a broken-image icon reads as a bug. Keying
    // off the failed URL rather than a boolean resets it when src changes.
    const [failedSrc, setFailedSrc] = useState<string | null>(null);

    if (src && src !== failedSrc) {
        return (
            <img
                src={src}
                alt={name || ''}
                className={cn('rounded-full object-contain', className)}
                onError={() => setFailedSrc(src)}
            />
        );
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

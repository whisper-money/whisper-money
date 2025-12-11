import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';

interface StepHeaderProps {
    icon: LucideIcon;
    iconContainerClassName?: string;
    title: string;
    description: string;
    /** Use larger icon container (h-24 w-24) for welcome/complete screens */
    large?: boolean;
}

export function StepHeader({
    icon: Icon,
    iconContainerClassName,
    title,
    description,
    large = false,
}: StepHeaderProps) {
    return (
        <>
            <div
                className={cn(
                    ' flex animate-in items-center justify-center rounded-full shadow-lg duration-500 zoom-in',
                    large ? 'size-24 mb-8' : 'size-16 mb-6',
                    iconContainerClassName,
                )}
            >
                <Icon
                    className={cn(
                        'text-white',
                        large ? 'h-12 w-12' : 'h-10 w-10',
                    )}
                />
            </div>

            <h1
                className={cn(
                    'text-center text-balance font-bold tracking-tight',
                    large ? 'text-4xl sm:text-4xl md:text-5xl mb-4' : 'text-3xl mb-2',
                )}
                dangerouslySetInnerHTML={{ __html: title }}
            />

            <p
                className={cn(
                    'mb-8 max-w-lg text-balance text-center text-muted-foreground',
                    large && 'text-lg',
                )}
            >
                {description}
            </p>
        </>
    );
}

import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';

interface StepHeaderProps {
    icon: LucideIcon;
    image?: string;
    iconContainerClassName?: string;
    title: string;
    description: string;
    /** Use larger icon container (h-24 w-24) for welcome/complete screens */
    large?: boolean;
}

export function StepHeader({
    icon: Icon,
    image,
    iconContainerClassName,
    title,
    description,
    large = false,
}: StepHeaderProps) {
    return (
        <>
            {!image && (
                <div
                    className={cn(
                        'flex animate-in items-center justify-center rounded-full shadow-lg duration-500 zoom-in',
                        large ? 'mb-8 size-24' : 'mb-6 size-16',
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
            )}

            {image && <img src={image} className="mb-8 size-72 rounded-full" />}

            <h1
                className={cn(
                    'text-center font-bold tracking-tight text-balance',
                    large
                        ? 'mb-4 text-4xl leading-tight sm:text-4xl md:text-5xl'
                        : 'mb-2 text-3xl',
                )}
                dangerouslySetInnerHTML={{ __html: title }}
            />

            <p
                className={cn(
                    'mb-8 max-w-lg text-center text-balance text-muted-foreground',
                    large && 'text-lg',
                )}
            >
                {description}
            </p>
        </>
    );
}

import * as React from 'react';

import { cn } from '@/lib/utils';

const InputGroup = React.forwardRef<
    HTMLDivElement,
    React.HTMLAttributes<HTMLDivElement>
>(({ className, ...props }, ref) => {
    return (
        <div
            ref={ref}
            className={cn(
                'relative flex w-full items-stretch overflow-hidden rounded-lg border border-input bg-background focus-within:ring-2 focus-within:ring-ring focus-within:ring-offset-2',
                className,
            )}
            {...props}
        />
    );
});
InputGroup.displayName = 'InputGroup';

const InputGroupAddon = React.forwardRef<
    HTMLDivElement,
    React.HTMLAttributes<HTMLDivElement> & {
        align?: 'inline-start' | 'inline-end' | 'block-end';
    }
>(({ className, align = 'inline-start', ...props }, ref) => {
    return (
        <div
            ref={ref}
            className={cn(
                'flex items-center justify-center bg-muted px-3 text-sm text-muted-foreground',
                align === 'inline-end' && 'order-last',
                align === 'block-end' && 'order-last w-full border-t',
                className,
            )}
            {...props}
        />
    );
});
InputGroupAddon.displayName = 'InputGroupAddon';

const InputGroupText = React.forwardRef<
    HTMLSpanElement,
    React.HTMLAttributes<HTMLSpanElement>
>(({ className, ...props }, ref) => {
    return (
        <span
            ref={ref}
            className={cn('whitespace-nowrap', className)}
            {...props}
        />
    );
});
InputGroupText.displayName = 'InputGroupText';

const InputGroupInput = React.forwardRef<
    HTMLInputElement,
    React.InputHTMLAttributes<HTMLInputElement>
>(({ className, type = 'text', ...props }, ref) => {
    return (
        <input
            type={type}
            className={cn(
                'flex h-10 w-full flex-1 border-0 bg-transparent px-3 py-2 text-base ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});
InputGroupInput.displayName = 'InputGroupInput';

const InputGroupTextarea = React.forwardRef<
    HTMLTextAreaElement,
    React.TextareaHTMLAttributes<HTMLTextAreaElement>
>(({ className, ...props }, ref) => {
    return (
        <textarea
            className={cn(
                'flex min-h-[80px] w-full flex-1 border-0 bg-transparent px-3 py-2 text-base ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                className,
            )}
            ref={ref}
            {...props}
        />
    );
});
InputGroupTextarea.displayName = 'InputGroupTextarea';

export {
    InputGroup,
    InputGroupAddon,
    InputGroupInput,
    InputGroupText,
    InputGroupTextarea,
};


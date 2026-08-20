import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface StepButtonProps {
    text: string;
    onClick?: () => void;
    disabled?: boolean;
    loading?: boolean;
    loadingText?: string;
    type?: 'button' | 'submit';
    /** Submits a form rendered outside the button, e.g. one in the step body. */
    form?: string;
    variant?: 'default' | 'outline' | 'ghost';
    icon?: LucideIcon;
    /** Rendered after the label, e.g. a keyboard shortcut hint. */
    trailing?: ReactNode;
    'data-testid'?: string;
    className?: string;
}

/**
 * The onboarding action: full width and 52px tall, so it stays an easy target
 * on a phone while keeping the app's radius and type.
 */
export function StepButton({
    text,
    onClick,
    disabled = false,
    loading = false,
    loadingText,
    type = 'button',
    form,
    variant = 'default',
    icon: Icon,
    trailing,
    'data-testid': testId,
    className = '',
}: StepButtonProps) {
    return (
        <Button
            type={type}
            form={form}
            variant={variant}
            onClick={onClick}
            disabled={disabled || loading}
            className={cn(
                'h-13 w-full rounded-lg text-[15px] font-medium',
                variant === 'ghost' && 'text-muted-foreground',
                className,
            )}
            data-testid={testId}
        >
            {loading ? (
                <>
                    <Spinner />
                    {loadingText || text}
                </>
            ) : (
                <>
                    {Icon && <Icon className="size-[18px]" />}
                    {text}
                    {trailing}
                </>
            )}
        </Button>
    );
}

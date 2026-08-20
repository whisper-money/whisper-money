import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface StepButtonBaseProps {
    text: string;
    onClick?: () => void;
    variant?: 'default' | 'outline' | 'ghost';
    icon?: LucideIcon;
    /** Rendered after the label, e.g. a keyboard shortcut hint. */
    trailing?: ReactNode;
    'data-testid'?: string;
    className?: string;
}

/**
 * A link and a button cannot be disabled or made to spin the same way, so the
 * two shapes are exclusive rather than one flat interface where `href` silently
 * swallows `disabled`. Passing both is a compile error, not a live spinner on a
 * clickable link.
 */
type StepButtonProps = StepButtonBaseProps &
    (
        | {
              /**
               * Renders as a link, for the destinations that have to leave the
               * SPA — Stripe checkout is a server redirect, so it cannot be an
               * Inertia visit. Keeps the anchor and the button as one element
               * rather than nesting a button inside an `<a>`.
               */
              href: string;
              disabled?: never;
              loading?: never;
              loadingText?: never;
              type?: never;
              form?: never;
          }
        | {
              href?: never;
              disabled?: boolean;
              loading?: boolean;
              loadingText?: string;
              type?: 'button' | 'submit';
              /** Submits a form rendered outside the button, e.g. in the step body. */
              form?: string;
          }
    );

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
    href,
    variant = 'default',
    icon: Icon,
    trailing,
    'data-testid': testId,
    className = '',
}: StepButtonProps) {
    const content = loading ? (
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
    );

    const shared = cn(
        'h-13 w-full rounded-lg text-[15px] font-medium',
        variant === 'ghost' && 'text-muted-foreground',
        className,
    );

    if (href) {
        return (
            <Button
                asChild
                variant={variant}
                className={shared}
                data-testid={testId}
            >
                <a href={href} onClick={onClick}>
                    {content}
                </a>
            </Button>
        );
    }

    return (
        <Button
            type={type}
            form={form}
            variant={variant}
            onClick={onClick}
            disabled={disabled || loading}
            className={shared}
            data-testid={testId}
        >
            {content}
        </Button>
    );
}

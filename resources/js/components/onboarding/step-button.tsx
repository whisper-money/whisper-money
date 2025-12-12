import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { cn } from '@/lib/utils';

interface StepButtonProps {
    text: string;
    onClick?: () => void;
    disabled?: boolean;
    loading?: boolean;
    loadingText?: string;
    type?: 'button' | 'submit';
    'data-testid'?: string;
    className?: string;
}

export function StepButton({
    text,
    onClick,
    disabled = false,
    loading = false,
    loadingText,
    type = 'button',
    'data-testid': testId,
    className = '',
}: StepButtonProps) {
    return (
        <Button
            type={type}
            size="lg"
            onClick={onClick}
            disabled={disabled || loading}
            className={cn(
                "group w-full gap-2 py-6 sm:w-auto sm:py-4",
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
                <>{text}</>
            )}
        </Button>
    );
}

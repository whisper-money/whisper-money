import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';

interface StepButtonProps {
    text: string;
    onClick?: () => void;
    disabled?: boolean;
    loading?: boolean;
    loadingText?: string;
    type?: 'button' | 'submit';
    'data-testid'?: string;
}

export function StepButton({
    text,
    onClick,
    disabled = false,
    loading = false,
    loadingText,
    type = 'button',
    'data-testid': testId,
}: StepButtonProps) {
    return (
        <Button
            type={type}
            size="lg"
            onClick={onClick}
            disabled={disabled || loading}
            className="group w-full gap-2 py-6 sm:w-auto sm:py-4"
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

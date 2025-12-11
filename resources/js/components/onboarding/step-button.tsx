import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { ArrowRight } from 'lucide-react';

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
            className="group w-full gap-2 py-6 sm:py-4 sm:w-auto"
            data-testid={testId}
        >
            {loading ? (
                <>
                    <Spinner />
                    {loadingText || text}
                </>
            ) : (
                <>
                    {text}
                </>
            )}
        </Button>
    );
}

import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { type AiSuggestionStatus } from '@/hooks/use-ai-column-suggestion';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

/**
 * The AI's place on a column-mapping step: a badge while the mapping is
 * exactly what it suggested, otherwise a button to ask it (again). Nothing at
 * all when AI column mapping is off.
 */
export function AiColumnDetection({
    status,
    onDetect,
    className,
}: {
    status: AiSuggestionStatus;
    onDetect: () => void;
    className?: string;
}) {
    if (status === 'hidden') {
        return null;
    }

    if (status === 'suggested') {
        return (
            <span
                role="status"
                className={cn(
                    'inline-flex w-fit items-center gap-1.5 rounded-md border border-violet-200 bg-violet-50 px-2 py-1 text-xs font-medium text-violet-900 dark:border-violet-900 dark:bg-violet-950/50 dark:text-violet-100',
                    className,
                )}
            >
                <AiSparkleIcon className="size-3.5" />
                {__('Suggested by AI')}
            </span>
        );
    }

    return (
        <Button
            type="button"
            variant="outline"
            size="sm"
            className={cn('w-fit', className)}
            disabled={status === 'loading'}
            aria-busy={status === 'loading'}
            onClick={onDetect}
        >
            {status === 'loading' ? (
                <Spinner className="size-3.5" />
            ) : (
                <AiSparkleIcon className="size-3.5" />
            )}
            {__('Detect columns with AI')}
        </Button>
    );
}

import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import {
    usePrivacyMode,
    usePrivacyReveal,
} from '@/contexts/privacy-mode-context';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Eye, EyeOff } from 'lucide-react';

interface PrivacyRevealButtonProps {
    /** Draws a vertical divider after the button, to set it apart from the controls that follow. */
    withSeparator?: boolean;
    className?: string;
}

/**
 * Eye button that shows or hides the amounts of the enclosing
 * PrivacyRevealScope. Only rendered inside a scope while global privacy mode
 * is on.
 */
export function PrivacyRevealButton({
    withSeparator = false,
    className,
}: PrivacyRevealButtonProps) {
    const { isGlobalPrivacyModeEnabled } = usePrivacyMode();
    const reveal = usePrivacyReveal();

    if (!isGlobalPrivacyModeEnabled || reveal === null) {
        return null;
    }

    const { isRevealed, toggleReveal } = reveal;

    const label = isRevealed
        ? __('Hide amounts in this block')
        : __('Show amounts in this block');

    return (
        <>
            <Tooltip>
                <TooltipTrigger asChild>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        aria-label={label}
                        aria-pressed={isRevealed}
                        onClick={toggleReveal}
                        className={cn(
                            'shrink-0 border border-transparent',
                            isRevealed
                                ? 'border-border bg-muted text-foreground'
                                : 'text-muted-foreground',
                            className,
                        )}
                    >
                        {isRevealed ? <Eye /> : <EyeOff />}
                    </Button>
                </TooltipTrigger>
                <TooltipContent side="bottom">{label}</TooltipContent>
            </Tooltip>
            {withSeparator && (
                <Separator orientation="vertical" className="!h-5" />
            )}
        </>
    );
}

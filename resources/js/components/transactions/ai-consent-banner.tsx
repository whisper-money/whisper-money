import { AiSparkleIcon } from '@/components/ui/ai-sparkle-icon';
import { Button } from '@/components/ui/button';
import { __ } from '@/utils/i18n';
import { ChevronRight, X } from 'lucide-react';

/** The banner above the first transaction asking to let AI categorize. */
export function AiConsentBanner({
    isSaving,
    onEnable,
    onDismiss,
}: {
    isSaving: boolean;
    onEnable: () => void;
    onDismiss: () => void;
}) {
    return (
        <div className="flex flex-col gap-3 bg-gradient-to-r from-violet-50 via-sky-50 to-rose-50 px-4 py-4 sm:flex-row sm:items-center sm:justify-between dark:from-violet-950/30 dark:via-sky-950/20 dark:to-rose-950/20">
            <div className="flex gap-3 sm:flex-1">
                <div className="flex size-9 shrink-0 items-center justify-center rounded-md bg-white/80 shadow-sm dark:bg-white/10">
                    <AiSparkleIcon className="h-5 w-5" />
                </div>
                <div className="max-w-80 sm:max-w-none">
                    <p className="font-medium">
                        {__('Let AI categorize your transactions')}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {__(
                            'Give your consent and our AI will suggest categories for your transactions automatically.',
                        )}
                    </p>
                </div>
            </div>
            <div className="flex items-center gap-2">
                <Button
                    onClick={onDismiss}
                    disabled={isSaving}
                    className="opacity-20 transition-all duration-300 hover:opacity-100"
                    variant="ghost"
                    size="icon"
                    aria-label={__('Dismiss')}
                >
                    <X />
                </Button>
                <Button
                    onClick={onEnable}
                    disabled={isSaving}
                    variant="secondary"
                    className="max-w-[calc(var(--spacing)_*_86)] bg-white px-6! dark:bg-white/10"
                >
                    {__('Enable AI')}
                    <ChevronRight />
                </Button>
            </div>
        </div>
    );
}

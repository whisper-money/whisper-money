import { Button } from '@/components/ui/button';
import { useClipboard } from '@/hooks/use-clipboard';
import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';
import { Copy } from 'lucide-react';
import { toast } from 'sonner';

/**
 * A value to paste somewhere else as is (a URL, a token, a command), with a
 * button that copies it.
 */
export function CopyableCode({
    value,
    className,
}: {
    value: string;
    className?: string;
}) {
    const [, copy] = useClipboard();

    function copyValue() {
        copy(value).then((ok) => {
            if (ok) {
                toast.success(__('Copied to clipboard'));
            }
        });
    }

    return (
        <div className={cn('flex min-w-0 items-center gap-2', className)}>
            <code className="min-w-0 flex-1 overflow-x-auto rounded-md bg-muted px-3 py-2 text-sm">
                {value}
            </code>
            <Button
                type="button"
                variant="outline"
                size="icon"
                onClick={copyValue}
                aria-label={__('Copy')}
            >
                <Copy className="h-4 w-4" />
            </Button>
        </div>
    );
}

import { Loader2Icon } from 'lucide-react';

import { cn } from '@/lib/utils';
import { __ } from '@/utils/i18n';

function Spinner({ className, ...props }: React.ComponentProps<'svg'>) {
    return (
        <Loader2Icon
            role="status"
            aria-label={__('Loading')}
            className={cn('size-4 animate-spin', className)}
            {...props}
        />
    );
}

export { Spinner };

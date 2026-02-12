import { Plus } from 'lucide-react';
import * as React from 'react';

import { Button } from '@/components/ui/button';

function CreateButton({
    children,
    ...props
}: React.ComponentProps<typeof Button>) {
    return (
        <Button {...props}>
            <Plus />
            {children}
        </Button>
    );
}

export { CreateButton };

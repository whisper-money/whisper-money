import { type SystemWidgetId } from '@/components/dashboard/widgets/registry';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/utils/i18n';
import { Plus } from 'lucide-react';
import { systemWidgetLabel } from './labels';

interface Props {
    available: SystemWidgetId[];
    onAdd: (widget: SystemWidgetId) => void;
    variant?: 'default' | 'outline';
}

/** Adds one of the system widgets not on the dashboard yet. */
export function AddWidgetMenu({
    available,
    onAdd,
    variant = 'outline',
}: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant={variant}
                    size="sm"
                    disabled={available.length === 0}
                >
                    <Plus className="size-4" />
                    {__('Add widget')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
                {available.map((widget) => (
                    <DropdownMenuItem
                        key={widget}
                        onSelect={() => onAdd(widget)}
                    >
                        {systemWidgetLabel(widget)}
                    </DropdownMenuItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

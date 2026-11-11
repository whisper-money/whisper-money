import { type SystemWidgetId } from '@/components/dashboard/widgets/registry';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type WidgetDefinitionSummary } from '@/types/dashboard';
import { __ } from '@/utils/i18n';
import { Plus, Sparkles } from 'lucide-react';
import { systemWidgetLabel } from './labels';

interface Props {
    available: SystemWidgetId[];
    onAdd: (widget: SystemWidgetId) => void;
    /** The user's own widgets not on the dashboard yet. */
    userWidgets?: WidgetDefinitionSummary[];
    onAddUserWidget?: (definitionId: string) => void;
    variant?: 'default' | 'outline';
}

/**
 * Adds one of the system widgets not on the dashboard yet, or one of the
 * widgets the user's agent wrote.
 */
export function AddWidgetMenu({
    available,
    onAdd,
    userWidgets = [],
    onAddUserWidget,
    variant = 'outline',
}: Props) {
    const offersUserWidgets =
        onAddUserWidget !== undefined && userWidgets.length > 0;

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant={variant}
                    size="sm"
                    disabled={available.length === 0 && !offersUserWidgets}
                >
                    <Plus className="size-4" />
                    {__('Add widget')}
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="max-h-96 max-w-72">
                {available.map((widget) => (
                    <DropdownMenuItem
                        key={widget}
                        onSelect={() => onAdd(widget)}
                    >
                        {systemWidgetLabel(widget)}
                    </DropdownMenuItem>
                ))}
                {offersUserWidgets && (
                    <>
                        {available.length > 0 && <DropdownMenuSeparator />}
                        <DropdownMenuLabel>
                            {__('Your widgets')}
                        </DropdownMenuLabel>
                        {userWidgets.map((definition) => (
                            <DropdownMenuItem
                                key={definition.id}
                                onSelect={() => onAddUserWidget(definition.id)}
                            >
                                <Sparkles className="size-4" />
                                <span className="truncate">
                                    {definition.name}
                                </span>
                            </DropdownMenuItem>
                        ))}
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

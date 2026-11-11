import {
    type SystemWidgetDefinition,
    type WidgetHeight,
    type WidgetSize,
    type WidgetWidth,
} from '@/components/dashboard/widgets/types';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { __ } from '@/utils/i18n';
import { Scaling } from 'lucide-react';
import { widgetHeightLabel, widgetWidthLabel } from './labels';

interface Props {
    definition: Pick<
        SystemWidgetDefinition,
        'allowedWidths' | 'allowedHeights'
    >;
    size: WidgetSize;
    /** The widget's name, for the trigger's accessible label. */
    label: string;
    onChange: (size: WidgetSize) => void;
}

/** Picks a widget's width and height among the ones it admits, and only those. */
export function WidgetSizeMenu({ definition, size, label, onChange }: Props) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button
                    variant="ghost"
                    size="icon-sm"
                    aria-label={__('Resize :widget', { widget: label })}
                >
                    <Scaling className="size-4" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                <DropdownMenuLabel>{__('Width')}</DropdownMenuLabel>
                <DropdownMenuRadioGroup
                    value={size.width}
                    onValueChange={(width) =>
                        onChange({ ...size, width: width as WidgetWidth })
                    }
                >
                    {definition.allowedWidths.map((width) => (
                        <DropdownMenuRadioItem key={width} value={width}>
                            {widgetWidthLabel(width)}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
                {definition.allowedHeights.length > 1 && (
                    <>
                        <DropdownMenuSeparator />
                        <DropdownMenuLabel>{__('Height')}</DropdownMenuLabel>
                        <DropdownMenuRadioGroup
                            value={size.height}
                            onValueChange={(height) =>
                                onChange({
                                    ...size,
                                    height: height as WidgetHeight,
                                })
                            }
                        >
                            {definition.allowedHeights.map((height) => (
                                <DropdownMenuRadioItem
                                    key={height}
                                    value={height}
                                >
                                    {widgetHeightLabel(height)}
                                </DropdownMenuRadioItem>
                            ))}
                        </DropdownMenuRadioGroup>
                    </>
                )}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

import { LabelCombobox } from '@/components/shared/label-combobox';
import { type Label } from '@/types/label';
import { __ } from '@/utils/i18n';
import { type ReactElement, useState } from 'react';

interface BulkLabelSelectProps {
    labels: Label[];
    onLabelsChange: (labelIds: string[]) => void;
    onLabelCreated?: (label: Label) => void;
    disabled?: boolean;
    /** A custom trigger, e.g. a button of the bulk actions bar. */
    trigger?: ReactElement;
}

export function BulkLabelSelect({
    labels,
    onLabelsChange,
    onLabelCreated,
    disabled = false,
    trigger,
}: BulkLabelSelectProps) {
    const [value, setValue] = useState<string[]>([]);

    function handleChange(newValue: string[]) {
        setValue(newValue);
        onLabelsChange(newValue);
    }

    return (
        <LabelCombobox
            value={value}
            onValueChange={handleChange}
            labels={labels}
            disabled={disabled}
            trigger={trigger}
            placeholder={__('Add labels')}
            triggerClassName="h-9 w-[180px] min-h-9"
            allowCreate={true}
            allowRemoveAll={true}
            onLabelCreated={onLabelCreated}
        />
    );
}

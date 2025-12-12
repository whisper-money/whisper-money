import { LabelCombobox } from '@/components/shared/label-combobox';
import { type Label } from '@/types/label';
import { useState } from 'react';

interface BulkLabelSelectProps {
    labels: Label[];
    onLabelsChange: (labelIds: string[]) => void;
    onLabelsUpdate?: (labels: Label[]) => void;
    disabled?: boolean;
}

export function BulkLabelSelect({
    labels,
    onLabelsChange,
    onLabelsUpdate,
    disabled = false,
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
            onLabelsChange={onLabelsUpdate}
            disabled={disabled}
            placeholder="Add labels"
            triggerClassName="h-9 w-[180px]"
            allowCreate={true}
        />
    );
}

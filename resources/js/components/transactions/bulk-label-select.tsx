import { LabelCombobox } from '@/components/shared/label-combobox';
import { type Label } from '@/types/label';
import { useState } from 'react';

interface BulkLabelSelectProps {
    labels: Label[];
    onLabelsChange: (labelIds: string[]) => void;
    disabled?: boolean;
}

export function BulkLabelSelect({
    labels,
    onLabelsChange,
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
            disabled={disabled}
            placeholder="Add labels"
            triggerClassName="h-9 w-[180px] min-h-9"
            allowCreate={true}
            allowRemoveAll={true}
        />
    );
}

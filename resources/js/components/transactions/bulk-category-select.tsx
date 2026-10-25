import { CategorySelect } from '@/components/transactions/category-select';
import { type PickerDrawer } from '@/components/ui/adaptive-popover';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { type ReactElement, useState } from 'react';

interface BulkCategorySelectProps {
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    disabled?: boolean;
    /** A custom trigger, e.g. a button of the bulk actions bar. */
    trigger?: ReactElement;
    /** Opens the picker as a bottom drawer, as the phone's bar does. */
    drawer?: PickerDrawer;
}

export function BulkCategorySelect({
    categories,
    onCategoryChange,
    disabled = false,
    trigger,
    drawer,
}: BulkCategorySelectProps) {
    const [value, setValue] = useState<string>('');

    function handleChange(newValue: string) {
        setValue(newValue);
        const categoryId = newValue === 'null' ? null : newValue;
        onCategoryChange(categoryId);
    }

    return (
        <CategorySelect
            value={value}
            onValueChange={handleChange}
            categories={categories}
            disabled={disabled}
            trigger={trigger}
            drawer={drawer}
            placeholder={__('Change category')}
            triggerClassName="h-9 w-[180px]"
            showUncategorized={true}
        />
    );
}

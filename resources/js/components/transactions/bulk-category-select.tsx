import { CategorySelect } from '@/components/transactions/category-select';
import { type Category } from '@/types/category';
import { __ } from '@/utils/i18n';
import { type ReactElement, useState } from 'react';

interface BulkCategorySelectProps {
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    disabled?: boolean;
    /** A custom trigger, e.g. a button of the bulk actions bar. */
    trigger?: ReactElement;
}

export function BulkCategorySelect({
    categories,
    onCategoryChange,
    disabled = false,
    trigger,
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
            placeholder={__('Change category')}
            triggerClassName="h-9 w-[180px]"
            showUncategorized={true}
        />
    );
}

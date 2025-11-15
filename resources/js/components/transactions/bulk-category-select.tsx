import { CategorySelect } from '@/components/transactions/category-select';
import { type Category } from '@/types/category';
import { useState } from 'react';

interface BulkCategorySelectProps {
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    disabled?: boolean;
}

export function BulkCategorySelect({
    categories,
    onCategoryChange,
    disabled = false,
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
            placeholder="Change category"
            triggerClassName="h-9 w-[180px]"
            showUncategorized={true}
        />
    );
}

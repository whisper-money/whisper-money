import { X, Trash2 } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    ButtonGroup,
    ButtonGroupSeparator,
    ButtonGroupText,
} from '@/components/ui/button-group';
import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { type Category } from '@/types/category';

interface BulkActionsBarProps {
    selectedCount: number;
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    onDelete: () => void;
    onClear: () => void;
    isUpdating?: boolean;
}

export function BulkActionsBar({
    selectedCount,
    categories,
    onCategoryChange,
    onDelete,
    onClear,
    isUpdating = false,
}: BulkActionsBarProps) {
    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="fixed bottom-6 left-1/2 -translate-x-1/2 z-50 animate-in fade-in slide-in-from-bottom-4 duration-300">
            <div className="flex flex-row justify-between items-center gap-10 bg-background py-2 px-4 border rounded-full shadow-lg">
                <div className='text-sm'>
                    {selectedCount} transaction{selectedCount !== 1 ? 's' : ''}{' '}
                    selected
                </div>

                <ButtonGroup>
                    <ButtonGroup>
                        <BulkCategorySelect
                            categories={categories}
                            onCategoryChange={onCategoryChange}
                            disabled={isUpdating}
                        />
                    </ButtonGroup>

                    <ButtonGroup>
                        <Button
                            variant="outline"
                            onClick={onDelete}
                            disabled={isUpdating}
                            className="text-red-600 hover:text-red-700 hover:bg-red-50 dark:hover:bg-red-950"
                        >
                            <Trash2 className="h-4 w-4" />
                            Delete
                        </Button>
                    </ButtonGroup>

                    <ButtonGroup>
                        <Button
                            variant="ghost"
                            size="icon"
                            onClick={onClear}
                            disabled={isUpdating}
                            aria-label="Clear selection"
                        >
                            <X className="h-4 w-4" />
                        </Button>
                    </ButtonGroup>
                </ButtonGroup>
            </div>
        </div>
    );
}


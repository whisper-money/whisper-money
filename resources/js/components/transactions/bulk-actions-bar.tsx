import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import { type Category } from '@/types/category';
import { RefreshCw, Trash2, X } from 'lucide-react';

interface BulkActionsBarProps {
    selectedCount: number;
    categories: Category[];
    onCategoryChange: (categoryId: number | null) => void;
    onDelete: () => void;
    onReEvaluateRules: () => void;
    onClear: () => void;
    isUpdating?: boolean;
}

export function BulkActionsBar({
    selectedCount,
    categories,
    onCategoryChange,
    onDelete,
    onReEvaluateRules,
    onClear,
    isUpdating = false,
}: BulkActionsBarProps) {
    if (selectedCount === 0) {
        return null;
    }

    return (
        <div className="fixed bottom-6 left-1/2 z-50 -translate-x-1/2 animate-in duration-300 fade-in slide-in-from-bottom-4">
            <div className="flex flex-row items-center justify-between gap-10 rounded-full border bg-background px-4 py-2 shadow-lg">
                <div className="text-sm">
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
                            onClick={onReEvaluateRules}
                            disabled={isUpdating}
                        >
                            <RefreshCw className="h-4 w-4" />
                            Re-evaluate Rules
                        </Button>
                    </ButtonGroup>

                    <ButtonGroup>
                        <Button
                            variant="outline"
                            onClick={onDelete}
                            disabled={isUpdating}
                            className="text-red-600 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950"
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

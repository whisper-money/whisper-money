import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
import { BulkLabelSelect } from '@/components/transactions/bulk-label-select';
import { Button } from '@/components/ui/button';
import { ButtonGroup } from '@/components/ui/button-group';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuGroup,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { type Category } from '@/types/category';
import { type Label } from '@/types/label';
import { MoreHorizontal, Trash2, WandSparkles, X } from 'lucide-react';

interface BulkActionsBarProps {
    selectedCount: number;
    categories: Category[];
    labels: Label[];
    onCategoryChange: (categoryId: number | null) => void;
    onLabelsChange: (labelIds: string[]) => void;
    onLabelsUpdate?: (labels: Label[]) => void;
    onDelete: () => void;
    onReEvaluateRules: () => void;
    onClear: () => void;
    isUpdating?: boolean;
}

export function BulkActionsBar({
    selectedCount,
    categories,
    labels,
    onCategoryChange,
    onLabelsChange,
    onLabelsUpdate,
    onDelete,
    onReEvaluateRules,
    onClear,
    isUpdating = false,
}: BulkActionsBarProps) {
    if (selectedCount < 1) {
        return null;
    }

    return (
        <div className="fixed bottom-6 flex w-full animate-in items-center justify-center duration-300 slide-in-from-bottom-5 slide-out-to-bottom-5 fade-in fade-out">
            <div className="flex max-w-[75%] flex-row items-center justify-between gap-10 rounded-full border bg-background px-4 py-2 shadow-lg">
                <div className="pl-2 text-sm">
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

                        <BulkLabelSelect
                            labels={labels}
                            onLabelsChange={onLabelsChange}
                            onLabelsUpdate={onLabelsUpdate}
                            disabled={isUpdating}
                        />

                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <Button
                                    variant="outline"
                                    size="icon"
                                    disabled={isUpdating}
                                    aria-label="More actions"
                                >
                                    <MoreHorizontal className="h-4 w-4" />
                                </Button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent align="end">
                                <DropdownMenuGroup>
                                    <DropdownMenuItem
                                        onClick={onReEvaluateRules}
                                        disabled={isUpdating}
                                    >
                                        <WandSparkles className="h-4 w-4" />
                                        Re-evaluate rules
                                    </DropdownMenuItem>

                                    <DropdownMenuItem
                                        variant="destructive"
                                        onSelect={onDelete}
                                    >
                                        <Trash2 />
                                        Delete
                                    </DropdownMenuItem>
                                </DropdownMenuGroup>
                            </DropdownMenuContent>
                        </DropdownMenu>
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

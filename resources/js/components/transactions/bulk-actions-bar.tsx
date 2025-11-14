import { BulkCategorySelect } from '@/components/transactions/bulk-category-select';
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
import { MoreHorizontal, Trash2, WandSparkles, X } from 'lucide-react';

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
